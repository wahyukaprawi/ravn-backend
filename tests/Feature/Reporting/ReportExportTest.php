<?php

namespace Tests\Feature\Reporting;

use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\ReportExportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

class ReportExportTest extends ReportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('reports');
        config(['queue.default' => 'sync']);
    }

    protected function export(User $actor, string $code = 'attendance.daily', array $filters = []): TestResponse
    {
        return $this->asUser($actor)->postJson('/api/v1/reports/'.$code.'/exports', array_merge([
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ], $filters));
    }

    public function test_rp_t02_export_runs_into_private_storage_with_expiry(): void
    {
        $this->addSession($this->employee);
        $this->addSession($this->teammate);
        $this->addSession($this->outsider);

        $response = $this->export($this->hr)->assertStatus(202);

        $response->assertJsonPath('data.status', ReportExport::STATUS_READY);
        $response->assertJsonPath('data.row_count', 3);
        $response->assertJsonMissingPath('data.last_error');
        $response->assertJsonMissingPath('data.path');

        $export = ReportExport::query()->firstOrFail();

        $this->assertSame('reports', $export->disk);
        $this->assertNotNull($export->checksum);
        $this->assertTrue(Storage::disk('reports')->exists($export->path));
        $this->assertSame(
            now()->addHours((int) config('reports.export_ttl_hours'))->timestamp,
            $export->expires_at->timestamp,
        );

        $disk = config('filesystems.disks.reports');
        $this->assertArrayNotHasKey('url', $disk);
        $this->assertStringNotContainsString('public', (string) $disk['root']);

        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'report.export.ready']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.export.requested', 'outcome' => 'success']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.export.ready', 'outcome' => 'success']);
    }

    public function test_rp_t02_export_requires_export_permission_and_report_scope(): void
    {
        $this->addSession($this->teammate);

        $this->asUser($this->employee)
            ->postJson('/api/v1/reports/attendance.daily/exports', ['scope' => 'all'])
            ->assertForbidden();

        $this->export($this->manager, 'attendance.daily', ['scope' => 'all'])->assertForbidden();

        $team = $this->export($this->manager, 'attendance.summary', ['scope' => 'team'])->assertStatus(202);
        $team->assertJsonPath('data.row_count', 1);
    }

    public function test_rp_t03_permission_is_rechecked_when_the_export_job_runs(): void
    {
        Queue::fake();

        $this->addSession($this->employee);

        $exporter = $this->user('employee');
        $exporter->givePermissionTo(['reports.view', 'reports.export', 'attendance.reports.view', 'attendance.view_any']);

        $response = $this->export($exporter)->assertStatus(202);
        $response->assertJsonPath('data.status', ReportExport::STATUS_PENDING);

        Queue::assertPushed(GenerateReportExport::class);

        $export = ReportExport::query()->firstOrFail();

        $exporter->revokePermissionTo('attendance.reports.view');

        (new GenerateReportExport($export->getKey()))->handle(app(ReportExportService::class));

        $export->refresh();
        $this->assertSame(ReportExport::STATUS_FAILED, $export->status);
        $this->assertSame('permission_revoked', $export->failure_code);
        $this->assertNull($export->path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.export.failed', 'outcome' => 'failure']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'report.export.failed']);
    }

    public function test_rp_t03_owner_downloads_the_export_and_it_is_audited(): void
    {
        $this->addSession($this->employee);
        $this->export($this->hr)->assertStatus(202);

        $export = ReportExport::query()->firstOrFail();

        $download = $this->asUser($this->hr)->get('/api/v1/reports/exports/'.$export->getKey().'/download');

        $download->assertOk();
        $this->assertStringContainsString('text/csv', (string) $download->headers->get('content-type'));
        $this->assertStringContainsString('Work date', $download->streamedContent());

        $export->refresh();
        $this->assertNotNull($export->downloaded_at);
        $this->assertSame($this->hr->getKey(), (int) $export->downloaded_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.export.downloaded', 'outcome' => 'success']);
    }

    public function test_rp_t03_non_owner_without_export_management_is_denied_and_audited(): void
    {
        $this->addSession($this->employee);
        $this->export($this->hr)->assertStatus(202);

        $export = ReportExport::query()->firstOrFail();

        $this->asUser($this->manager)
            ->getJson('/api/v1/reports/exports/'.$export->getKey().'/download')
            ->assertForbidden();

        $this->assertDatabaseHas('audit_logs', ['action' => 'report.export.download_denied', 'outcome' => 'failure']);
    }

    public function test_rp_t03_hr_with_export_management_can_download_another_users_export(): void
    {
        $this->addSession($this->teammate);
        $this->export($this->manager, 'attendance.summary', ['scope' => 'team'])->assertStatus(202);

        $export = ReportExport::query()->firstOrFail();
        $this->assertNotSame($this->hr->getKey(), (int) $export->user_id);

        $this->asUser($this->hr)
            ->get('/api/v1/reports/exports/'.$export->getKey().'/download')
            ->assertOk();

        $export->refresh();
        $this->assertSame($this->hr->getKey(), (int) $export->downloaded_by);
    }

    public function test_rp_t03_expired_exports_return_gone(): void
    {
        $this->addSession($this->employee);
        $this->export($this->hr)->assertStatus(202);

        $export = ReportExport::query()->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-06-16 11:00:00', 'Asia/Jakarta'));

        $response = $this->asUser($this->hr)
            ->getJson('/api/v1/reports/exports/'.$export->getKey().'/download')
            ->assertStatus(410);

        $response->assertJsonPath('error.code', 'EXPIRED');
    }

    public function test_rp_t03_revoked_report_permission_blocks_download(): void
    {
        $this->addSession($this->employee);

        $exporter = $this->user('employee');
        $exporter->givePermissionTo(['reports.view', 'reports.export', 'attendance.reports.view', 'attendance.view_any']);

        $this->export($exporter)->assertStatus(202);

        $export = ReportExport::query()->firstOrFail();

        $exporter->revokePermissionTo('attendance.reports.view');

        $this->asUser($exporter)
            ->getJson('/api/v1/reports/exports/'.$export->getKey().'/download')
            ->assertForbidden();

        $this->assertDatabaseHas('audit_logs', ['action' => 'report.export.download_denied', 'outcome' => 'failure']);
    }

    public function test_rp_t02_expired_exports_are_pruned_and_files_removed(): void
    {
        $this->addSession($this->employee);
        $this->export($this->hr)->assertStatus(202);

        $export = ReportExport::query()->firstOrFail();
        $path = $export->path;

        Carbon::setTestNow(Carbon::parse('2026-06-16 11:00:00', 'Asia/Jakarta'));

        $this->artisan('reports:prune')->assertSuccessful();

        $export->refresh();
        $this->assertSame(ReportExport::STATUS_EXPIRED, $export->status);
        $this->assertNull($export->path);
        $this->assertFalse(Storage::disk('reports')->exists($path));
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.export.expired', 'outcome' => 'success']);
    }

    public function test_rp_t05_export_listing_stays_within_permissions(): void
    {
        $this->addSession($this->employee);
        $this->export($this->hr)->assertStatus(202);

        $mine = $this->asUser($this->hr)->getJson('/api/v1/reports/exports')->assertOk();
        $mine->assertJsonCount(1, 'data');

        $this->asUser($this->manager)->getJson('/api/v1/reports/exports')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->manager)->getJson('/api/v1/reports/exports?scope=all')->assertForbidden();

        $all = $this->asUser($this->hr)->getJson('/api/v1/reports/exports?scope=all')->assertOk();
        $all->assertJsonCount(1, 'data');

        $this->asUser($this->manager)
            ->getJson('/api/v1/reports/exports/'.ReportExport::query()->firstOrFail()->getKey())
            ->assertForbidden();
    }
}
