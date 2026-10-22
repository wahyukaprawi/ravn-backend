<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;

class LeaveBalanceTest extends LeaveTestCase
{
    public function test_lv_t03_submission_reserves_balance(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')->assertCreated();

        $balance = $this->balance($employee, $type, 2027);

        $this->assertSame('2.00', $balance->reserved);
        $this->assertSame('0.00', $balance->used);
        $this->assertEquals(8.0, $balance->available());

        $this->assertDatabaseHas('leave_balance_ledger', [
            'user_id' => $employee->getKey(),
            'leave_type_id' => $type->getKey(),
            'entry_type' => 'reserve',
        ]);
    }

    public function test_lv_t04_final_approval_converts_reservation_to_usage(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->assertCreated()
            ->json('data.id');

        $this->loginAs($manager)
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING);

        $afterManager = $this->balance($employee, $type, 2027);
        $this->assertSame('2.00', $afterManager->reserved);
        $this->assertSame('0.00', $afterManager->used);

        $hr = $this->userWithRole('hr_admin');

        $this->loginAs($hr)
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_APPROVED);

        $afterHr = $this->balance($employee, $type, 2027);
        $this->assertSame('0.00', $afterHr->reserved);
        $this->assertSame('2.00', $afterHr->used);
        $this->assertEquals(8.0, $afterHr->available());

        $this->assertSame(1, LeaveBalanceLedger::query()
            ->where('leave_request_id', $requestId)
            ->where('entry_type', 'use')
            ->count());
    }

    public function test_lv_t05_rejection_releases_the_reservation(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->assertCreated()
            ->json('data.id');

        $this->loginAs($manager)
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'reject', 'reason' => 'Coverage'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_REJECTED);

        $balance = $this->balance($employee, $type, 2027);

        $this->assertSame('0.00', $balance->reserved);
        $this->assertSame('0.00', $balance->used);
        $this->assertEquals(10.0, $balance->available());

        $this->assertDatabaseHas('leave_balance_ledger', [
            'leave_request_id' => $requestId,
            'entry_type' => 'release',
        ]);
    }

    public function test_lv_t05_pending_cancellation_releases_the_reservation(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->assertCreated()
            ->json('data.id');

        $this->loginAs($employee)
            ->postJson("/api/v1/leave/requests/{$requestId}/cancel", ['reason' => 'Plans changed'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_CANCELED);

        $balance = $this->balance($employee, $type, 2027);

        $this->assertSame('0.00', $balance->reserved);
        $this->assertEquals(10.0, $balance->available());
    }

    public function test_lv_t06_insufficient_balance_counts_other_pending_reservations(): void
    {
        [$employee, , $type] = $this->scenario(3);

        $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')->assertCreated();

        $this->submitRequest($employee, $type, '2027-03-04', '2027-03-05')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(1, LeaveRequest::query()->count());

        $balance = $this->balance($employee, $type, 2027);
        $this->assertSame('2.00', $balance->reserved);
        $this->assertEquals(1.0, $balance->available());
    }

    public function test_lv_t06_balance_cannot_be_negative_without_an_authorized_adjustment(): void
    {
        [$employee, , $type] = $this->scenario(1);

        $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')->assertStatus(422);

        $this->assertEquals(1.0, $this->balance($employee, $type, 2027)->available());
    }

    public function test_an_hr_admin_can_apply_an_audited_balance_adjustment(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $hr = $this->userWithRole('hr_admin');

        $this->loginAs($hr)->postJson('/api/v1/admin/leave/balances/adjust', [
            'user_id' => $employee->getKey(),
            'leave_type_id' => $type->getKey(),
            'year' => 2027,
            'amount' => 3,
            'reason' => 'Carry-over approved in writing',
        ])->assertOk()->assertJsonPath('data.adjusted', 3);

        $this->assertDatabaseHas('leave_balance_ledger', [
            'user_id' => $employee->getKey(),
            'entry_type' => 'adjustment',
            'amount' => 3,
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.balance.adjusted']);
    }

    public function test_a_manager_cannot_adjust_a_balance(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $this->loginAs($manager)->postJson('/api/v1/admin/leave/balances/adjust', [
            'user_id' => $employee->getKey(),
            'leave_type_id' => $type->getKey(),
            'year' => 2027,
            'amount' => 3,
            'reason' => 'Not allowed',
        ])->assertStatus(403);
    }
}
