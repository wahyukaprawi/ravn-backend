<?php

namespace Tests\Feature\EndToEnd;

use App\Models\ReportExport;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Reporting\ReportTestCase;

class ReportingReleaseFlowTest extends ReportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('reports');
        config(['queue.default' => 'sync']);
    }

    public function test_e2e_06_reporting_export_download_audit_and_scope_denial(): void
    {
        $this->addSession($this->employee);

        $report = $this->runReport($this->hr, 'attendance.daily', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ])->assertOk();

        $report->assertJsonPath('data.row_count', 1);
        $report->assertJsonPath('data.rows.0.user_id', $this->employee->getKey());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'report.viewed',
            'outcome' => 'success',
        ]);

        $this->asUser($this->hr)
            ->postJson('/api/v1/reports/attendance.daily/exports', [
                'from' => '2026-06-01',
                'to' => '2026-06-30',
                'scope' => 'all',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.status', ReportExport::STATUS_READY)
            ->assertJsonPath('data.row_count', 1);

        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'report.export.ready']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.export.requested']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.export.ready']);

        $export = ReportExport::query()->firstOrFail();
        $this->assertTrue(Storage::disk('reports')->exists($export->path));

        $download = $this->asUser($this->hr)
            ->get('/api/v1/reports/exports/'.$export->getKey().'/download')
            ->assertOk();

        $this->assertStringContainsString('text/csv', (string) $download->headers->get('content-type'));
        $this->assertStringContainsString('Work date', $download->streamedContent());

        $export->refresh();
        $this->assertNotNull($export->downloaded_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.export.downloaded']);

        $this->asUser($this->manager)
            ->getJson('/api/v1/reports/exports/'.$export->getKey().'/download')
            ->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'report.export.download_denied',
            'outcome' => 'failure',
        ]);

        $this->asUser($this->manager)
            ->getJson('/api/v1/reports/attendance.daily?from=2026-06-01&to=2026-06-30&scope=all')
            ->assertForbidden();
    }
}
