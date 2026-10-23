<?php

namespace Tests\Feature\Employee;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;

class EmployeeApiTest extends EmployeeTestCase
{
    public function test_emp_t01_employee_numbers_and_work_emails_follow_the_unique_rules(): void
    {
        $admin = $this->user('super_admin');

        $created = $this->actingAs($admin)->postJson('/api/v1/employees', [
            'employee_no' => 'emp-001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'work_email' => 'ADA@Example.TEST',
            'hire_date' => '2026-01-01',
        ])->assertCreated()
            ->assertJsonPath('data.employee_no', 'EMP-001')
            ->assertJsonPath('data.work_email', 'ada@example.test');

        $this->actingAs($admin)->postJson('/api/v1/employees', [
            'employee_no' => 'EMP-001',
            'first_name' => 'Duplicate',
            'hire_date' => '2026-01-01',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->actingAs($admin)->postJson('/api/v1/employees', [
            'employee_no' => 'EMP-002',
            'first_name' => 'Duplicate email',
            'work_email' => 'ada@example.test',
            'hire_date' => '2026-01-01',
        ])->assertStatus(422);

        $this->actingAs($admin)->deleteJson('/api/v1/employees/'.$created->json('data.id'))->assertOk();

        $this->actingAs($admin)->postJson('/api/v1/employees', [
            'employee_no' => 'EMP-001',
            'first_name' => 'Permanent number',
            'hire_date' => '2026-01-01',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/api/v1/employees', [
            'employee_no' => 'EMP-003',
            'first_name' => 'Reused email',
            'work_email' => 'ada@example.test',
            'hire_date' => '2026-01-01',
        ])->assertCreated();
    }

    public function test_emp_t01_location_codes_are_permanent_and_department_codes_are_reusable(): void
    {
        $admin = $this->user('super_admin');

        $branch = $this->actingAs($admin)->postJson('/api/v1/admin/work-locations', [
            'code' => 'br1',
            'name' => 'Branch One',
            'address' => '1 Example Street',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'timezone' => 'Asia/Jakarta',
        ])->assertCreated()->assertJsonPath('data.code', 'BR1')->json('data.id');

        $this->actingAs($admin)->deleteJson('/api/v1/admin/work-locations/'.$branch)->assertOk();

        $this->actingAs($admin)->postJson('/api/v1/admin/work-locations', [
            'code' => 'BR1',
            'name' => 'Branch One again',
            'address' => '1 Example Street',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'timezone' => 'Asia/Jakarta',
        ])->assertStatus(422);

        $department = Department::query()->create(['code' => 'OPS', 'name' => 'Operations']);
        $this->actingAs($admin)->deleteJson('/api/v1/admin/departments/'.$department->id)->assertOk();

        $this->actingAs($admin)->postJson('/api/v1/admin/departments', [
            'code' => 'ops',
            'name' => 'Operations again',
        ])->assertCreated()->assertJsonPath('data.code', 'OPS');
    }

    public function test_emp_t05_profiles_are_visible_only_to_self_direct_manager_or_view_all(): void
    {
        $managerUser = $this->user('manager');
        $managerEmployee = $this->staff($managerUser, 'MGR-001');

        $reportUser = $this->user('employee');
        $reportEmployee = $this->staff($reportUser, 'EMP-001', $managerUser);

        $other = $this->staff($this->user('employee'), 'EMP-002');
        $hr = $this->user('hr_admin');

        $this->actingAs($managerUser)->getJson('/api/v1/employees/'.$reportEmployee->id)->assertOk();
        $this->actingAs($managerUser)->getJson('/api/v1/employees/'.$other->id)->assertForbidden();
        $this->actingAs($reportUser)->getJson('/api/v1/employees/'.$reportEmployee->id)->assertOk();
        $this->actingAs($reportUser)->getJson('/api/v1/employees/'.$other->id)->assertForbidden();
        $this->actingAs($reportUser)->getJson('/api/v1/employees')->assertForbidden();

        $list = $this->actingAs($managerUser)->getJson('/api/v1/employees')->assertOk()->json('data');
        $ids = collect($list)->pluck('id')->all();

        $this->assertContains($managerEmployee->id, $ids);
        $this->assertContains($reportEmployee->id, $ids);
        $this->assertNotContains($other->id, $ids);

        $this->actingAs($hr)->getJson('/api/v1/employees/'.$other->id)->assertOk();
        $this->actingAs($hr)->getJson('/api/v1/employees')->assertOk()->assertJsonCount(3, 'data');

        $this->actingAs($reportUser)->patchJson('/api/v1/employees/'.$reportEmployee->id, [
            'first_name' => 'Self edit',
        ])->assertForbidden();

        $this->actingAs($managerUser)->patchJson('/api/v1/employees/'.$reportEmployee->id, [
            'first_name' => 'Manager edit',
        ])->assertForbidden();
    }

    public function test_emp_t11_delete_and_restore_guard_history_and_links(): void
    {
        $admin = $this->user('super_admin');

        $active = $this->staff($this->user('employee'), 'EMP-100');
        $this->actingAs($admin)->deleteJson('/api/v1/employees/'.$active->id)->assertStatus(422);

        $empty = $this->employeeProfile('EMP-101');
        $this->actingAs($admin)->deleteJson('/api/v1/employees/'.$empty->id)->assertOk();
        $this->assertSoftDeleted('employees', ['id' => $empty->id]);

        $this->actingAs($admin)->postJson('/api/v1/employees/'.$empty->id.'/restore')->assertOk();
        $this->assertNotSoftDeleted('employees', ['id' => $empty->id]);

        $deleted = $this->employeeProfile('EMP-102');
        $deleted->forceFill(['work_email' => 'reuse@example.test'])->save();
        $this->actingAs($admin)->deleteJson('/api/v1/employees/'.$deleted->id)->assertOk();

        $this->actingAs($admin)->postJson('/api/v1/employees', [
            'employee_no' => 'EMP-103',
            'first_name' => 'Reuser',
            'work_email' => 'reuse@example.test',
            'hire_date' => '2026-01-01',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/employees/'.$deleted->id.'/restore')->assertStatus(422);

        $historical = $this->employeeProfile('EMP-104');
        $employment = EmployeeEmployment::query()->create([
            'employee_id' => $historical->id,
            'employment_type' => 'permanent',
            'effective_from' => '2024-01-01',
            'effective_to' => '2024-12-31',
        ]);
        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $historical->id,
            'employee_employment_id' => $employment->id,
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'work_location_id' => $this->location->id,
            'effective_from' => '2024-01-01',
            'effective_to' => '2024-12-31',
        ]);

        $this->actingAs($admin)->deleteJson('/api/v1/employees/'.$historical->id)->assertOk();
        $this->assertSoftDeleted('employees', ['id' => $historical->id]);
        $this->assertDatabaseHas('employee_employments', ['id' => $employment->id]);
        $this->assertDatabaseHas('employee_assignments', ['id' => $assignment->id]);

        $this->actingAs($admin)->deleteJson('/api/v1/admin/departments/'.$this->department->id)->assertStatus(422);
        $this->actingAs($admin)->deleteJson('/api/v1/admin/positions/'.$this->position->id)->assertStatus(422);
        $this->actingAs($admin)->deleteJson('/api/v1/admin/work-locations/'.$this->location->id)->assertStatus(422);

        $this->assertTrue(AuditLog::query()->where('action', 'employee.deleted')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'employee.restored')->exists());
    }
}
