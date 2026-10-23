<?php

namespace App\Services;

use App\Models\Report;
use App\Models\User;
use App\Reporting\ReportDefinition;
use App\Reporting\ReportRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ReportService
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ManagerResolver $managers,
    ) {}

    public function catalogue(): Collection
    {
        return Report::query()->where('active', true)->orderBy('name')->get();
    }

    public function find(string $code): ?Report
    {
        return Report::query()->where('code', $code)->where('active', true)->first();
    }

    public function definition(Report $report): ?ReportDefinition
    {
        return $this->registry->get($report->code);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{report: Report, definition: ReportDefinition, filters: array<string, mixed>, scope: string, rows: list<array<string, mixed>>, totals: array<string, int|float|null>}
     */
    public function run(User $actor, Report $report, array $filters): array
    {
        $definition = $this->definition($report);

        if ($definition === null) {
            throw new AuthorizationException('This report is not available.');
        }

        $filters = $this->prepareFilters($definition, $filters);
        $scope = $filters['scope'];
        $userIds = $this->authorizeScope($actor, $report, $definition, $scope);

        if ($filters['user_id'] !== null) {
            if ($scope !== 'all') {
                throw ValidationException::withMessages(['user_id' => 'Filtering by user requires the all scope.']);
            }

            $userIds = [(int) $filters['user_id']];
        }

        $result = $definition->build($filters, $userIds);
        $rows = $result['rows'];

        if (count($rows) > (int) config('reports.max_rows')) {
            throw ValidationException::withMessages([
                'filters' => 'The report result exceeds the maximum number of rows. Narrow the date range.',
            ]);
        }

        return [
            'report' => $report,
            'definition' => $definition,
            'filters' => $filters,
            'scope' => $scope,
            'rows' => $rows,
            'totals' => $result['totals'],
        ];
    }

    public function authorizeScope(User $actor, Report $report, ReportDefinition $definition, string $scope): ?array
    {
        if ($report->permission !== null && ! $actor->hasPermissionTo($report->permission)) {
            throw new AuthorizationException('You are not allowed to view this report.');
        }

        if ($report->sensitive && ! $actor->hasPermissionTo('reports.view_sensitive')) {
            throw new AuthorizationException('This report requires sensitive-report access.');
        }

        $permission = $definition->scopePermissions()[$scope] ?? null;

        if ($permission !== null && ! $actor->hasPermissionTo($permission)) {
            throw new AuthorizationException('You are not allowed to view this scope.');
        }

        return match ($scope) {
            'self' => [$actor->getKey()],
            'team' => $this->managers->directReportIds($actor),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{from: string, to: string, scope: string, user_id: int|null}
     */
    public function prepareFilters(ReportDefinition $definition, array $input): array
    {
        $to = (string) ($input['to'] ?? now()->toDateString());
        $from = (string) ($input['from'] ?? now()->subDays((int) config('reports.default_range_days'))->toDateString());

        if (! $this->isDateString($from) || ! $this->isDateString($to)) {
            throw ValidationException::withMessages(['from' => 'Dates must use the Y-m-d format.']);
        }

        $fromDate = Carbon::createFromFormat('Y-m-d', $from);
        $toDate = Carbon::createFromFormat('Y-m-d', $to);

        if ($fromDate->greaterThan($toDate)) {
            throw ValidationException::withMessages(['from' => 'The from date must be before or equal to the to date.']);
        }

        if ($fromDate->diffInDays($toDate) > (int) config('reports.max_range_days')) {
            throw ValidationException::withMessages(['from' => 'The date range exceeds the maximum allowed window.']);
        }

        $scope = (string) ($input['scope'] ?? 'self');

        if (! in_array($scope, $definition->scopes(), true)) {
            throw ValidationException::withMessages(['scope' => 'The requested scope is not available for this report.']);
        }

        $userId = $input['user_id'] ?? null;

        return [
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'scope' => $scope,
            'user_id' => $userId === null || $userId === '' ? null : (int) $userId,
        ];
    }

    public function renderCsv(ReportDefinition $definition, array $rows): string
    {
        $columns = $definition->columns();
        $stream = fopen('php://temp', 'r+');

        fputcsv($stream, array_values($columns));

        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn (string $key) => $row[$key] ?? null, array_keys($columns)));
        }

        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    protected function isDateString(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
