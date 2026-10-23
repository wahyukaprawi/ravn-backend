<?php

namespace Tests\Feature\Reporting;

class ReportScopeTest extends ReportTestCase
{
    public function test_rp_t01_report_catalogue_requires_report_permission(): void
    {
        $this->asUser($this->employee)->getJson('/api/v1/reports')->assertForbidden();

        $this->asUser($this->manager)->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_rp_t01_attendance_reports_stay_within_the_requested_scope(): void
    {
        $this->addSession($this->employee, ['late_minutes' => 10]);
        $this->addSession($this->teammate);
        $this->addSession($this->outsider);

        $team = $this->runReport($this->manager, 'attendance.summary', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'team',
        ])->assertOk();

        $team->assertJsonPath('data.row_count', 2);
        $this->assertEqualsCanonicalizing(
            [$this->employee->getKey(), $this->teammate->getKey()],
            collect($team->json('data.rows'))->pluck('user_id')->all(),
        );

        $this->runReport($this->manager, 'attendance.summary', ['scope' => 'all'])->assertForbidden();

        $all = $this->runReport($this->hr, 'attendance.summary', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ])->assertOk();

        $all->assertJsonPath('data.row_count', 3);
    }

    public function test_rp_t01_all_scope_can_filter_a_single_employee(): void
    {
        $this->addSession($this->employee);
        $this->addSession($this->teammate);

        $response = $this->runReport($this->hr, 'attendance.summary', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
            'user_id' => $this->teammate->getKey(),
        ])->assertOk();

        $response->assertJsonPath('data.row_count', 1);
        $response->assertJsonPath('data.rows.0.user_id', $this->teammate->getKey());

        $this->runReport($this->manager, 'attendance.summary', [
            'scope' => 'team',
            'user_id' => $this->employee->getKey(),
        ])->assertStatus(422);
    }

    public function test_rp_t01_report_specific_permission_is_enforced(): void
    {
        $viewer = $this->user('employee');
        $viewer->givePermissionTo('reports.view');

        $this->runReport($viewer, 'attendance.summary', ['scope' => 'self'])->assertForbidden();
    }

    public function test_rp_t01_unknown_reports_and_invalid_filters_are_rejected(): void
    {
        $this->runReport($this->hr, 'attendance.unknown')->assertNotFound();

        $this->runReport($this->hr, 'attendance.summary', ['scope' => 'bogus'])->assertStatus(422);

        $this->runReport($this->hr, 'audit.activity', ['scope' => 'self'])->assertStatus(422);

        $this->runReport($this->hr, 'attendance.summary', [
            'from' => '2025-01-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ])->assertStatus(422);

        $this->runReport($this->hr, 'attendance.summary', [
            'from' => '2026-06-30',
            'to' => '2026-06-01',
            'scope' => 'all',
        ])->assertStatus(422);
    }
}
