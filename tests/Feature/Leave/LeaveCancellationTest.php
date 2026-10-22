<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\User;

class LeaveCancellationTest extends LeaveTestCase
{
    public function test_lv_t11_pending_request_is_canceled_immediately(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->json('data.id');

        $this->loginAs($employee)
            ->postJson("/api/v1/leave/requests/{$requestId}/cancel", ['reason' => 'Plans changed'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_CANCELED);

        $this->assertSame('0.00', $this->balance($employee, $type, 2027)->reserved);
        $this->assertSame(0, LeaveCancellationRequest::query()->count());
    }

    public function test_lv_t11_approved_not_started_requires_an_approved_cancellation(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->json('data.id');

        $this->approve($requestId, $manager);

        $this->loginAs($employee)
            ->postJson("/api/v1/leave/requests/{$requestId}/cancel", ['reason' => 'Travel plans changed'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', LeaveRequest::STATUS_APPROVED)
            ->assertJsonPath('cancellation.status', LeaveCancellationRequest::STATUS_PENDING);

        $this->assertSame('2.00', $this->balance($employee, $type, 2027)->used);

        $cancellationId = LeaveCancellationRequest::query()->firstOrFail()->getKey();

        $this->loginAs($manager)
            ->postJson("/api/v1/leave/cancellations/{$cancellationId}/decision", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveCancellationRequest::STATUS_PENDING);

        $this->assertSame(LeaveRequest::STATUS_APPROVED, LeaveRequest::query()->findOrFail($requestId)->status);
        $this->assertSame('2.00', $this->balance($employee, $type, 2027)->used);

        $this->loginAs($this->userWithRole('hr_admin'))
            ->postJson("/api/v1/leave/cancellations/{$cancellationId}/decision", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveCancellationRequest::STATUS_APPROVED);

        $this->assertSame(LeaveRequest::STATUS_CANCELED, LeaveRequest::query()->findOrFail($requestId)->status);
        $this->assertSame('0.00', $this->balance($employee, $type, 2027)->used);
    }

    public function test_lv_t11_a_rejected_cancellation_keeps_the_leave_approved(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->json('data.id');

        $this->approve($requestId, $manager);

        $this->loginAs($employee)
            ->postJson("/api/v1/leave/requests/{$requestId}/cancel", ['reason' => 'Changed'])
            ->assertStatus(202);

        $cancellationId = LeaveCancellationRequest::query()->firstOrFail()->getKey();

        $this->loginAs($manager)
            ->postJson("/api/v1/leave/cancellations/{$cancellationId}/decision", ['action' => 'reject', 'reason' => 'Coverage'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveCancellationRequest::STATUS_REJECTED);

        $this->assertSame(LeaveRequest::STATUS_APPROVED, LeaveRequest::query()->findOrFail($requestId)->status);
        $this->assertSame('2.00', $this->balance($employee, $type, 2027)->used);
    }

    public function test_lv_t11_started_leave_cannot_be_canceled(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $request = $this->approvedRequest($employee, $manager, $type, now()->subDays(2)->toDateString(), now()->subDay()->toDateString());

        $this->loginAs($employee)
            ->postJson("/api/v1/leave/requests/{$request->getKey()}/cancel", ['reason' => 'Too late'])
            ->assertStatus(422);

        $this->assertSame(LeaveRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    public function test_lv_t11_rejected_request_cannot_be_canceled(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $request = $this->approvedRequest($employee, $manager, $type, '2027-03-01', '2027-03-02', LeaveRequest::STATUS_REJECTED);

        $this->loginAs($employee)
            ->postJson("/api/v1/leave/requests/{$request->getKey()}/cancel", ['reason' => 'Nope'])
            ->assertStatus(422);
    }

    public function test_lv_t11_only_the_owner_can_cancel_a_request(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->json('data.id');

        $other = $this->userWithRole('employee');

        $this->loginAs($other)
            ->postJson("/api/v1/leave/requests/{$requestId}/cancel", ['reason' => 'Not mine'])
            ->assertStatus(403);
    }

    protected function approve(int $requestId, User $manager): void
    {
        $this->loginAs($manager)
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk();

        $this->loginAs($this->userWithRole('hr_admin'))
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk();
    }

    protected function approvedRequest(
        User $employee,
        User $manager,
        $type,
        string $start,
        string $end,
        string $status = LeaveRequest::STATUS_APPROVED,
    ): LeaveRequest {
        return LeaveRequest::query()->create([
            'user_id' => $employee->getKey(),
            'leave_type_id' => $type->getKey(),
            'manager_id' => $manager->getKey(),
            'start_date' => $start,
            'end_date' => $end,
            'days' => 2,
            'status' => $status,
            'submitted_by' => $employee->getKey(),
            'submitted_at' => now(),
        ]);
    }
}
