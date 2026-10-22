<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveRequest;
use App\Models\WorkflowStep;

class LeaveApprovalFlowTest extends LeaveTestCase
{
    public function test_lv_t08_submission_does_not_auto_approve(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->assertCreated()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING)
            ->assertJsonPath('data.workflow.status', 'in_progress')
            ->assertJsonPath('data.workflow.current_step.key', 'manager')
            ->json('data.id');

        $request = LeaveRequest::query()->findOrFail($requestId);

        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->status);
        $this->assertSame($manager->getKey(), $request->manager_id);
    }

    public function test_lv_t08_hr_admin_cannot_bypass_the_manager_stage(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->json('data.id');

        $this->loginAs($this->userWithRole('hr_admin'))
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertStatus(403);

        $this->assertSame(LeaveRequest::STATUS_PENDING, LeaveRequest::query()->findOrFail($requestId)->status);
    }

    public function test_lv_t08_manager_then_hr_admin_approves_sequentially(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->json('data.id');

        $this->loginAs($manager)
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING)
            ->assertJsonPath('data.workflow.current_step.key', 'hr_admin');

        $this->loginAs($manager)
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertStatus(403);

        $hr = $this->userWithRole('hr_admin');

        $this->loginAs($hr)
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_APPROVED)
            ->assertJsonPath('data.workflow.status', 'approved');

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.request.approve']);
    }

    public function test_lv_t08_hr_admin_cannot_act_after_the_workflow_is_complete(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->json('data.id');

        $this->loginAs($manager)
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk();

        $hr = $this->userWithRole('hr_admin');

        $this->loginAs($hr)
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk();

        $this->loginAs($manager)
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertStatus(422);
    }

    public function test_lv_t09_self_approval_is_blocked(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02');

        $request = LeaveRequest::query()->firstOrFail();
        $request->forceFill(['user_id' => $manager->getKey()])->save();

        $step = WorkflowStep::query()->where('position', 1)->firstOrFail();
        $step->forceFill(['assignee_id' => $manager->getKey()])->save();

        $this->loginAs($manager)
            ->postJson("/api/v1/leave/approvals/{$request->getKey()}/decision", ['action' => 'approve'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_lv_t09_a_request_without_a_manager_is_held(): void
    {
        $this->calendar();
        $type = $this->leaveType();
        $this->policy($type);
        $employee = $this->userWithRole('employee');
        $this->grantBalance($employee, $type, 2027, 10);

        $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->assertCreated()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_HELD)
            ->assertJsonPath('data.workflow', null);

        $request = LeaveRequest::query()->firstOrFail();

        $this->assertSame('2.00', $this->balance($employee, $type, 2027)->reserved);

        $this->loginAs($this->userWithRole('hr_admin'))
            ->postJson("/api/v1/leave/approvals/{$request->getKey()}/decision", ['action' => 'approve'])
            ->assertStatus(422);
    }

    public function test_lv_t09_an_authorized_hr_admin_can_assign_an_approver_to_a_held_request(): void
    {
        $this->calendar();
        $type = $this->leaveType();
        $this->policy($type);
        $employee = $this->userWithRole('employee');
        $this->grantBalance($employee, $type, 2027, 10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->assertCreated()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_HELD)
            ->json('data.id');

        $manager = $this->userWithRole('manager');

        $hr = $this->userWithRole('hr_admin');

        $this->loginAs($hr)
            ->postJson("/api/v1/leave/requests/{$requestId}/assign-approver", ['manager_id' => $manager->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING)
            ->assertJsonPath('data.workflow.current_step.key', 'manager');

        $this->loginAs($manager)
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk();

        $this->loginAs($hr)
            ->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_APPROVED);
    }

    public function test_lv_t09_a_manager_cannot_assign_themselves_to_a_held_request(): void
    {
        $this->calendar();
        $type = $this->leaveType();
        $this->policy($type);
        $employee = $this->userWithRole('employee');
        $this->grantBalance($employee, $type, 2027, 10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')->json('data.id');

        $this->loginAs($this->userWithRole('manager'))
            ->postJson("/api/v1/leave/requests/{$requestId}/assign-approver", ['manager_id' => $employee->getKey()])
            ->assertStatus(403);

        $this->assertSame(LeaveRequest::STATUS_HELD, LeaveRequest::query()->findOrFail($requestId)->status);
    }
}
