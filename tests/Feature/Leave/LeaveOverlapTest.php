<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveRequest;

class LeaveOverlapTest extends LeaveTestCase
{
    public function test_lv_t07_overlapping_pending_request_is_rejected(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $this->submitRequest($employee, $type, '2027-03-01', '2027-03-03')->assertCreated();

        $this->submitRequest($employee, $type, '2027-03-02', '2027-03-04')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_lv_t07_overlapping_approved_request_is_rejected(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->assertCreated()
            ->json('data.id');

        $this->loginAs($manager)
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk();

        $this->loginAs($this->userWithRole('hr_admin'))
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk();

        $this->submitRequest($employee, $type, '2027-03-02', '2027-03-02')
            ->assertStatus(422);
    }

    public function test_lv_t07_adjacent_dates_are_allowed(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')->assertCreated();
        $this->submitRequest($employee, $type, '2027-03-03', '2027-03-04')->assertCreated();

        $this->assertSame(2, LeaveRequest::query()->count());
    }

    public function test_lv_t07_a_canceled_request_no_longer_blocks_dates(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->assertCreated()
            ->json('data.id');

        $this->loginAs($employee)
            ->postJson("/api/v1/leave/requests/{$requestId}/cancel", ['reason' => 'Changed plans'])
            ->assertOk();

        $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')->assertCreated();
    }
}
