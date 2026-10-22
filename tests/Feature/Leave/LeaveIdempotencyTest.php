<?php

namespace Tests\Feature\Leave;

use App\Models\IdempotencyKey;
use App\Models\LeaveRequest;
use App\Models\WorkflowStep;

class LeaveIdempotencyTest extends LeaveTestCase
{
    public function test_lv_t12_identical_submission_replays_the_stored_response(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $payload = [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-02',
            'reason' => 'Family matters',
        ];

        $first = $this->loginAs($employee)->postJson('/api/v1/leave/requests', $payload, [
            'Idempotency-Key' => 'submit-1',
        ]);

        $first->assertCreated();

        $second = $this->postJson('/api/v1/leave/requests', $payload, [
            'Idempotency-Key' => 'submit-1',
        ]);

        $second->assertCreated();
        $second->assertHeader('Idempotency-Key', 'submit-1');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_lv_t12_same_key_with_a_different_payload_is_a_conflict(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $this->loginAs($employee)->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-02',
        ], ['Idempotency-Key' => 'submit-2'])->assertCreated();

        $this->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-04',
            'end_date' => '2027-03-05',
        ], ['Idempotency-Key' => 'submit-2'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_lv_t12_keys_are_scoped_per_actor(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $this->loginAs($employee)->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-02',
        ], ['Idempotency-Key' => 'shared-key'])->assertCreated();

        $other = $this->userWithRole('employee');
        $this->grantBalance($other, $type, 2027, 10);

        $this->loginAs($other)->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-02',
        ], ['Idempotency-Key' => 'shared-key'])->assertCreated();

        $this->assertSame(2, LeaveRequest::query()->count());
    }

    public function test_lv_t12_a_failed_request_does_not_consume_the_key(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $this->loginAs($employee)->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-06',
            'end_date' => '2027-03-07',
        ], ['Idempotency-Key' => 'submit-3'])->assertStatus(422);

        $this->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-02',
        ], ['Idempotency-Key' => 'submit-3'])->assertCreated();
    }

    public function test_lv_t12_a_key_with_an_in_flight_request_is_a_conflict(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $payload = [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-02',
        ];

        $this->loginAs($employee);

        IdempotencyKey::query()->create([
            'key' => hash('sha256', $employee->getKey().'|in-flight'),
            'actor_id' => $employee->getKey(),
            'method' => 'POST',
            'path' => 'api/v1/leave/requests',
            'request_hash' => hash('sha256', 'POST|api/v1/leave/requests|'.json_encode($payload)),
            'response_status' => 0,
            'response_body' => [],
            'created_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);

        $this->postJson('/api/v1/leave/requests', $payload, ['Idempotency-Key' => 'in-flight'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_lv_t12_identical_decisions_execute_once(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->json('data.id');

        $this->loginAs($manager);

        $payload = ['action' => 'approve'];

        $first = $this->postJson("/api/v1/leave/approvals/{$requestId}/decision", $payload, [
            'Idempotency-Key' => 'decision-1',
        ]);

        $first->assertOk()->assertJsonPath('data.workflow.current_step.key', 'hr_admin');

        $second = $this->postJson("/api/v1/leave/approvals/{$requestId}/decision", $payload, [
            'Idempotency-Key' => 'decision-1',
        ]);

        $second->assertOk();

        $this->assertEquals($first->json('data.workflow'), $second->json('data.workflow'));
        $this->assertSame(1, WorkflowStep::query()->where('status', WorkflowStep::STATUS_APPROVED)->count());
    }

    public function test_lv_t12_a_second_decision_with_a_new_key_is_rejected_as_stale(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->json('data.id');

        $this->loginAs($manager);

        $this->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'], [
            'Idempotency-Key' => 'decision-2',
        ])->assertOk();

        $this->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'], [
            'Idempotency-Key' => 'decision-3',
        ])->assertStatus(403);

        $this->assertSame(LeaveRequest::STATUS_PENDING, LeaveRequest::query()->findOrFail($requestId)->status);
        $this->assertSame(1, WorkflowStep::query()->where('status', WorkflowStep::STATUS_APPROVED)->count());
    }
}
