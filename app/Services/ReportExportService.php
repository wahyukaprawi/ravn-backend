<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\AuditLog;
use App\Models\Report;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ReportExportService
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function request(User $actor, Report $report, array $filters, string $format = 'csv'): ReportExport
    {
        if (! in_array($format, (array) config('reports.formats'), true)) {
            throw ValidationException::withMessages(['format' => 'The requested export format is not supported.']);
        }

        $definition = $this->reports->definition($report);

        if ($definition === null) {
            throw new AuthorizationException('This report is not available.');
        }

        $prepared = $this->reports->prepareFilters($definition, $filters);
        $this->reports->authorizeScope($actor, $report, $definition, $prepared['scope']);

        if ($prepared['user_id'] !== null && $prepared['scope'] !== 'all') {
            throw ValidationException::withMessages(['user_id' => 'Filtering by user requires the all scope.']);
        }

        return DB::transaction(function () use ($actor, $report, $prepared, $format): ReportExport {
            $export = ReportExport::query()->create([
                'user_id' => $actor->getKey(),
                'report_id' => $report->getKey(),
                'report_code' => $report->code,
                'status' => ReportExport::STATUS_PENDING,
                'format' => $format,
                'scope' => $prepared['scope'],
                'filters' => [
                    'from' => $prepared['from'],
                    'to' => $prepared['to'],
                    'scope' => $prepared['scope'],
                    'user_id' => $prepared['user_id'],
                ],
                'disk' => (string) config('reports.disk'),
                'expires_at' => now()->addHours((int) config('reports.export_ttl_hours')),
            ]);

            $this->audit->record('report.export.requested', $export, null, [
                'report_code' => $report->code,
                'format' => $format,
                'scope' => $prepared['scope'],
                'from' => $prepared['from'],
                'to' => $prepared['to'],
            ], $actor);

            $this->outbox->dispatch('report.export.requested', [
                'export_id' => $export->getKey(),
                'report_code' => $report->code,
                'scope' => $prepared['scope'],
            ], ReportExport::class, $export->getKey());

            return $export;
        });
    }

    public function generate(int $exportId): void
    {
        $export = ReportExport::query()->find($exportId);

        if ($export === null || $export->status !== ReportExport::STATUS_PENDING) {
            return;
        }

        try {
            $report = $this->reports->find($export->report_code);

            if ($report === null) {
                $this->fail($export, 'report_unavailable', 'The report is no longer available.');

                return;
            }

            $owner = User::query()->find($export->user_id);

            if ($owner === null || ! $owner->isActive()) {
                $this->fail($export, 'actor_inactive', 'The export owner is no longer active.');

                return;
            }

            $result = $this->reports->run($owner, $report, $export->filters ?? []);
            $content = $this->reports->renderCsv($result['definition'], $result['rows']);
            $path = 'exports/'.Str::uuid().'.'.$export->format;

            Storage::disk($export->disk)->put($path, $content);

            DB::transaction(function () use ($export, $owner, $path, $content, $result): void {
                $export->forceFill([
                    'status' => ReportExport::STATUS_READY,
                    'path' => $path,
                    'checksum' => hash('sha256', $content),
                    'row_count' => count($result['rows']),
                    'failure_code' => null,
                    'last_error' => null,
                ])->save();

                $this->audit->record('report.export.ready', $export, null, [
                    'report_code' => $export->report_code,
                    'row_count' => count($result['rows']),
                    'checksum' => hash('sha256', $content),
                    'expires_at' => $export->expires_at?->toIso8601String(),
                ], $owner);

                $this->outbox->dispatch('report.export.ready', [
                    'export_id' => $export->getKey(),
                    'report_code' => $export->report_code,
                    'row_count' => count($result['rows']),
                    'expires_at' => $export->expires_at?->toIso8601String(),
                ], ReportExport::class, $export->getKey());
            });
        } catch (AuthorizationException) {
            $this->fail($export, 'permission_revoked', 'Access to this report was revoked before the export ran.');
        } catch (ValidationException) {
            $this->fail($export, 'invalid_filters', 'The stored export filters are no longer valid.');
        } catch (Throwable) {
            $this->fail($export, 'generation_failed', 'The export could not be generated.');
        }
    }

    public function canView(ReportExport $export, User $actor): bool
    {
        return (int) $export->user_id === (int) $actor->getKey()
            || $actor->hasPermissionTo('reports.manage_exports');
    }

    public function authorizeDownload(ReportExport $export, User $actor): void
    {
        if (! $actor->hasPermissionTo('reports.view')) {
            $this->denyDownload($export, $actor, 'missing_reports_view');

            throw new AuthorizationException('You are not allowed to download report exports.');
        }

        if (! $actor->hasPermissionTo('reports.manage_exports') && (int) $export->user_id !== (int) $actor->getKey()) {
            $this->denyDownload($export, $actor, 'not_owner');

            throw new AuthorizationException('You are not allowed to download this export.');
        }

        $report = Report::query()->where('code', $export->report_code)->where('active', true)->first();

        if ($report === null) {
            $this->denyDownload($export, $actor, 'report_unavailable');

            throw new AuthorizationException('This report is not available.');
        }

        if ($report->permission !== null && ! $actor->hasPermissionTo($report->permission)) {
            $this->denyDownload($export, $actor, 'report_permission_revoked');

            throw new AuthorizationException('You are not allowed to download this report.');
        }

        if ($report->sensitive && ! $actor->hasPermissionTo('reports.view_sensitive')) {
            $this->denyDownload($export, $actor, 'sensitive_permission_revoked');

            throw new AuthorizationException('This report requires sensitive-report access.');
        }

        if ($export->status !== ReportExport::STATUS_READY) {
            throw new HttpException(409, 'This export is not ready for download.');
        }

        if ($export->expires_at !== null && $export->expires_at->isPast()) {
            throw new HttpException(410, 'This export has expired.');
        }

        if (empty($export->path) || ! Storage::disk($export->disk)->exists($export->path)) {
            throw new HttpException(410, 'This export is no longer available.');
        }
    }

    public function markDownloaded(ReportExport $export, User $actor): ReportExport
    {
        return DB::transaction(function () use ($export, $actor): ReportExport {
            $export->forceFill([
                'downloaded_at' => now(),
                'downloaded_by' => $actor->getKey(),
            ])->save();

            $this->audit->record('report.export.downloaded', $export, null, [
                'report_code' => $export->report_code,
                'downloaded_by' => $actor->getKey(),
            ], $actor);

            return $export;
        });
    }

    public function prune(int $limit = 500): int
    {
        $exports = ReportExport::query()
            ->where('status', ReportExport::STATUS_READY)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($exports as $export) {
            if (! empty($export->path)) {
                Storage::disk($export->disk)->delete($export->path);
            }

            DB::transaction(function () use ($export): void {
                $export->forceFill([
                    'status' => ReportExport::STATUS_EXPIRED,
                    'path' => null,
                ])->save();

                $this->audit->record('report.export.expired', $export, null, [
                    'report_code' => $export->report_code,
                ]);
            });
        }

        return $exports->count();
    }

    protected function fail(ReportExport $export, string $failureCode, string $message): void
    {
        DB::transaction(function () use ($export, $failureCode, $message): void {
            $export->forceFill([
                'status' => ReportExport::STATUS_FAILED,
                'failure_code' => $failureCode,
                'last_error' => $message,
            ])->save();

            $this->audit->record('report.export.failed', $export, null, [
                'report_code' => $export->report_code,
                'failure_code' => $failureCode,
            ], null, AuditLog::OUTCOME_FAILURE);

            $this->outbox->dispatch('report.export.failed', [
                'export_id' => $export->getKey(),
                'report_code' => $export->report_code,
                'failure_code' => $failureCode,
            ], ReportExport::class, $export->getKey());
        });
    }

    protected function denyDownload(ReportExport $export, User $actor, string $reason): void
    {
        $this->audit->record('report.export.download_denied', $export, null, [
            'reason' => $reason,
        ], $actor, AuditLog::OUTCOME_FAILURE);
    }
}
