<?php

namespace Tests\Feature\Employee;

use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;
use App\Models\HistoryCorrection;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class HistoryCorrectionTest extends EmployeeTestCase
{
    protected function target(): array
    {
        $employee = $this->employeeProfile('EMP-500', '2024-01-01');
        $employment = EmployeeEmployment::query()->create([
            'employee_id' => $employee->id,
            'employment_type' => 'permanent',
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);
        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id,
            'employee_employment_id' => $employment->id,
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'work_location_id' => $this->location->id,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        return [$employee, $employment, $assignment];
    }

    protected function createCorrection(User $requester, array $changes, ?UploadedFile $evidence = null)
    {
        return $this->withToken($this->freshMfaToken($requester))
            ->post('/api/v1/admin/history-corrections', [
                'entity_type' => 'assignment',
                'entity_id' => $changes['entity_id'],
                'changes' => $changes['changes'],
                'reason' => 'Correcting an incorrect effective date.',
                'evidence' => $evidence,
            ], ['Accept' => 'application/json']);
    }

    public function test_emp_t12_correction_requires_three_distinct_people_and_executes_atomically(): void
    {
        [, , $assignment] = $this->target();

        $requester = $this->user('hr_admin');
        $approver = $this->user('hr_admin');
        $executor = $this->user('hr_admin');

        $created = $this->createCorrection($requester, [
            'entity_id' => $assignment->id,
            'changes' => ['effective_from' => '2024-02-01'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $requesterToken = $this->freshMfaToken($requester);
        $approverToken = $this->freshMfaToken($approver);
        $executorToken = $this->freshMfaToken($executor);

        $this->freshRequest()->withToken($requesterToken)->postJson('/api/v1/admin/history-corrections/'.$created.'/submit')
            ->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->freshRequest()->withToken($requesterToken)->postJson('/api/v1/admin/history-corrections/'.$created.'/approve')
            ->assertForbidden();

        $this->freshRequest()->withToken($approverToken)->postJson('/api/v1/admin/history-corrections/'.$created.'/approve')
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->freshRequest()->withToken($approverToken)->postJson('/api/v1/admin/history-corrections/'.$created.'/execute')
            ->assertForbidden();

        $this->freshRequest()->withToken($executorToken)->postJson('/api/v1/admin/history-corrections/'.$created.'/execute')
            ->assertOk()
            ->assertJsonPath('data.status', 'executed')
            ->assertJsonPath('data.executor.id', $executor->id);

        $this->assertSame('2024-02-01', $assignment->fresh()->effective_from->toDateString());
        $this->assertSame('executed', HistoryCorrection::query()->find($created)->status);
        $this->assertTrue(HistoryCorrection::query()->find($created)->executor_id === $executor->id);
    }

    public function test_emp_t12_failed_execution_keeps_the_correction_approved_and_leaves_the_target_unchanged(): void
    {
        [, , $assignment] = $this->target();
        $assignment->forceFill(['effective_to' => '2024-05-31'])->save();

        $second = EmployeeAssignment::query()->create([
            'employee_id' => $assignment->employee_id,
            'employee_employment_id' => $assignment->employee_employment_id,
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'work_location_id' => $this->location->id,
            'effective_from' => '2024-06-01',
            'effective_to' => '2024-12-31',
        ]);

        $requester = $this->user('hr_admin');
        $approver = $this->user('hr_admin');
        $executor = $this->user('hr_admin');

        $correctionId = $this->createCorrection($requester, [
            'entity_id' => $assignment->id,
            'changes' => ['effective_to' => '2024-12-31'],
        ])->assertCreated()->json('data.id');

        $this->freshRequest()->withToken($this->freshMfaToken($requester))
            ->postJson('/api/v1/admin/history-corrections/'.$correctionId.'/submit')
            ->assertOk();

        $this->freshRequest()->withToken($this->freshMfaToken($approver))
            ->postJson('/api/v1/admin/history-corrections/'.$correctionId.'/approve')
            ->assertOk();

        $this->freshRequest()->withToken($this->freshMfaToken($executor))
            ->postJson('/api/v1/admin/history-corrections/'.$correctionId.'/execute')
            ->assertStatus(422);

        $this->assertSame('2024-05-31', $assignment->fresh()->effective_to->toDateString());
        $this->assertSame('approved', HistoryCorrection::query()->find($correctionId)->status);
        $this->assertNull(HistoryCorrection::query()->find($correctionId)->executed_at);
    }

    public function test_history_correction_evidence_stays_private_and_downloadable_by_authorized_users(): void
    {
        Storage::fake('local');

        [, , $assignment] = $this->target();
        $requester = $this->user('hr_admin');

        $correctionId = $this->createCorrection($requester, [
            'entity_id' => $assignment->id,
            'changes' => ['effective_from' => '2024-02-01'],
        ], UploadedFile::fake()->create('evidence.pdf', 10, 'application/pdf'))
            ->assertCreated()
            ->assertJsonPath('data.has_evidence', true)
            ->json('data.id');

        $correction = HistoryCorrection::query()->findOrFail($correctionId);
        Storage::disk('local')->assertExists($correction->evidence_path);

        $this->withToken($this->freshMfaToken($requester))
            ->get('/api/v1/admin/history-corrections/'.$correctionId.'/evidence')
            ->assertOk();
    }
}
