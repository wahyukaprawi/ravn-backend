<?php

namespace Tests\Feature\Employee;

use App\Models\Department;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class EmploymentAssignmentTest extends EmployeeTestCase
{
    protected function employmentPayload(string $from = '2026-01-01', ?string $to = null): array
    {
        return [
            'employment_type' => 'permanent',
            'effective_from' => $from,
            'effective_to' => $to,
        ];
    }

    protected function assignmentPayload(EmployeeEmployment $employment, string $from, ?string $to = null, ?int $managerId = null, ?int $departmentId = null): array
    {
        return [
            'employee_employment_id' => $employment->getKey(),
            'department_id' => $departmentId ?? $this->department->getKey(),
            'position_id' => $this->position->getKey(),
            'manager_id' => $managerId,
            'work_location_id' => $this->location->getKey(),
            'effective_from' => $from,
            'effective_to' => $to,
        ];
    }

    public function test_emp_t02_inclusive_dates_and_first_employment_hire_date_are_enforced(): void
    {
        $hr = $this->user('hr_admin');
        $employee = $this->employeeProfile('EMP-200', '2026-01-01');

        $this->actingAs($hr)->postJson('/api/v1/employees/'.$employee->id.'/employments', $this->employmentPayload('2026-01-02'))
            ->assertStatus(422);

        $this->actingAs($hr)->postJson('/api/v1/employees/'.$employee->id.'/employments', $this->employmentPayload('2026-01-01', '2025-12-31'))
            ->assertStatus(422);

        $this->actingAs($hr)->postJson('/api/v1/employees/'.$employee->id.'/employments', $this->employmentPayload('2026-01-01'))
            ->assertCreated()
            ->assertJsonPath('data.effective_from', '2026-01-01');

        $this->expectException(QueryException::class);

        DB::table('employee_employments')->insert([
            'employee_id' => $employee->id,
            'employment_type' => 'permanent',
            'effective_from' => '2026-02-01',
            'effective_to' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_emp_t03_overlapping_employments_are_rejected_and_gaps_are_allowed(): void
    {
        $hr = $this->user('hr_admin');
        $employee = $this->employeeProfile('EMP-201', '2026-01-01');

        $this->actingAs($hr)->postJson('/api/v1/employees/'.$employee->id.'/employments', $this->employmentPayload('2026-01-01', '2026-05-31'))
            ->assertCreated();

        $this->actingAs($hr)->postJson('/api/v1/employees/'.$employee->id.'/employments', $this->employmentPayload('2026-07-01'))
            ->assertCreated();

        $this->actingAs($hr)->postJson('/api/v1/employees/'.$employee->id.'/employments', $this->employmentPayload('2026-05-01', '2026-06-15'))
            ->assertStatus(422);
    }

    public function test_emp_t04_assignment_overlap_and_employment_containment_are_enforced(): void
    {
        $hr = $this->user('hr_admin');
        $employee = $this->employeeProfile('EMP-202', '2026-01-01');
        $employment = EmployeeEmployment::query()->create([
            'employee_id' => $employee->id,
            'employment_type' => 'permanent',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);

        $this->actingAs($hr)->postJson('/api/v1/employees/'.$employee->id.'/assignments', $this->assignmentPayload($employment, '2026-06-15', '2026-06-30'))
            ->assertCreated();
        $this->actingAs($hr)->postJson('/api/v1/employees/'.$employee->id.'/assignments', $this->assignmentPayload($employment, '2026-07-01', '2026-07-31'))
            ->assertCreated();

        $this->actingAs($hr)->postJson('/api/v1/employees/'.$employee->id.'/assignments', $this->assignmentPayload($employment, '2026-06-20', '2026-07-10'))
            ->assertStatus(422);

        $this->actingAs($hr)->postJson('/api/v1/employees/'.$employee->id.'/assignments', $this->assignmentPayload($employment, '2027-01-01'))
            ->assertStatus(422);
    }

    public function test_emp_t06_future_assignment_is_editable_and_cancellable_but_effective_history_is_locked(): void
    {
        $hr = $this->user('hr_admin');
        $employee = $this->employeeProfile('EMP-203', '2026-01-01');
        $employment = EmployeeEmployment::query()->create([
            'employee_id' => $employee->id,
            'employment_type' => 'permanent',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $predecessor = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id,
            'employee_employment_id' => $employment->id,
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'work_location_id' => $this->location->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $replacement = $this->actingAs($hr)->postJson(
            '/api/v1/employees/'.$employee->id.'/assignments',
            $this->assignmentPayload($employment, '2026-08-01'),
        )->assertCreated()->json('data.id');

        $this->assertSame('2026-07-31', $predecessor->fresh()->effective_to->toDateString());

        $otherDepartment = Department::query()->create(['code' => 'OPS', 'name' => 'Operations']);

        $this->actingAs($hr)->patchJson('/api/v1/assignments/'.$replacement, [
            'department_id' => $otherDepartment->id,
        ])->assertOk()->assertJsonPath('data.department.code', 'OPS');

        $this->actingAs($hr)->deleteJson('/api/v1/assignments/'.$replacement)
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        $this->assertNull($predecessor->fresh()->effective_to);

        $this->actingAs($hr)->patchJson('/api/v1/assignments/'.$predecessor->id, [
            'department_id' => $otherDepartment->id,
        ])->assertStatus(422);

        $this->actingAs($hr)->patchJson('/api/v1/assignments/'.$predecessor->id, [
            'effective_to' => '2026-07-31',
        ])->assertOk();

        $history = $this->actingAs($hr)->getJson('/api/v1/employees/'.$employee->id.'/history')
            ->assertOk()
            ->json('data.assignments');

        $this->assertCount(2, $history);
        $this->assertSame('canceled', collect($history)->firstWhere('id', $replacement)['status']);
    }

    public function test_emp_t07_self_managers_missing_managers_and_reporting_cycles_are_rejected(): void
    {
        $hr = $this->user('hr_admin');

        $aUser = $this->user('manager');
        $bUser = $this->user('manager');
        $a = $this->staff($aUser, 'MGR-100', $bUser);
        $b = $this->staff($bUser, 'MGR-101', $aUser);

        $aEmployment = $a->employments()->firstOrFail();

        $this->actingAs($hr)->postJson('/api/v1/employees/'.$a->id.'/assignments', $this->assignmentPayload($aEmployment, '2026-08-01', null, $aUser->id))
            ->assertStatus(422);

        $cUser = $this->user('employee');
        $c = $this->employeeProfile('EMP-204', '2026-01-01', $cUser);
        $cEmployment = EmployeeEmployment::query()->create([
            'employee_id' => $c->id,
            'employment_type' => 'permanent',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $this->actingAs($hr)->postJson('/api/v1/employees/'.$c->id.'/assignments', $this->assignmentPayload($cEmployment, '2026-06-15', null, $bUser->id))
            ->assertStatus(422);

        $dUser = $this->user('employee');
        $this->employeeProfile('EMP-205', '2026-01-01', $dUser);

        $this->actingAs($hr)->postJson('/api/v1/employees/'.$c->id.'/assignments', $this->assignmentPayload($cEmployment, '2026-06-15', null, $dUser->id))
            ->assertStatus(422);

        $this->assertTrue($a->fresh()->exists);
    }
}
