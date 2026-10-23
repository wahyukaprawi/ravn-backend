<?php

namespace Tests\Feature\Reporting;

class AuditReportTest extends ReportTestCase
{
    public function test_rp_t05_sensitive_audit_report_requires_sensitive_permission(): void
    {
        $this->runReport($this->hr, 'audit.activity', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ])->assertForbidden();

        $this->runReport($this->manager, 'audit.activity', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ])->assertForbidden();

        $this->runReport($this->superAdmin, 'audit.activity', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ])->assertOk();
    }

    public function test_rp_t01_audit_report_rejects_non_all_scopes(): void
    {
        $this->runReport($this->superAdmin, 'audit.activity', ['scope' => 'self'])->assertStatus(422);
        $this->runReport($this->superAdmin, 'audit.activity', ['scope' => 'team'])->assertStatus(422);
    }

    public function test_rp_t05_audit_report_exposes_no_before_after_payloads(): void
    {
        $this->runReport($this->hr, 'attendance.summary', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'report.viewed', 'outcome' => 'success']);

        $response = $this->runReport($this->superAdmin, 'audit.activity', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ])->assertOk();

        $this->assertGreaterThanOrEqual(1, $response->json('data.row_count'));

        foreach ($response->json('data.rows') as $row) {
            $this->assertArrayNotHasKey('before', $row);
            $this->assertArrayNotHasKey('after', $row);
        }

        foreach ($response->json('data.columns') as $column) {
            $this->assertNotContains($column['key'], ['before', 'after']);
        }
    }
}
