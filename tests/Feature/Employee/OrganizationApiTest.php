<?php

namespace Tests\Feature\Employee;

use App\Models\Department;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;
use App\Models\Position;
use App\Models\WorkLocation;

class OrganizationApiTest extends EmployeeTestCase
{
    public function test_organization_codes_and_work_location_validation_are_normalized(): void
    {
        $admin = $this->user('super_admin');

        $department = $this->actingAs($admin)->postJson('/api/v1/admin/departments', [
            'code' => ' fin ',
            'name' => 'Finance',
        ])->assertCreated()->assertJsonPath('data.code', 'FIN')->json('data.id');

        $this->actingAs($admin)->postJson('/api/v1/admin/departments', [
            'code' => 'FIN',
            'name' => 'Duplicate',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/api/v1/admin/positions', [
            'code' => 'acc',
            'name' => 'Accountant',
        ])->assertCreated()->assertJsonPath('data.code', 'ACC');

        $this->actingAs($admin)->postJson('/api/v1/admin/work-locations', [
            'code' => 'branch',
            'name' => 'Branch',
            'address' => '2 Example Street',
            'latitude' => -6.3,
            'longitude' => 106.9,
            'radius_meters' => 0,
            'timezone' => 'Asia/Jakarta',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/api/v1/admin/work-locations', [
            'code' => 'branch',
            'name' => 'Branch',
            'address' => '2 Example Street',
            'latitude' => -6.3,
            'longitude' => 106.9,
            'radius_meters' => 50,
            'timezone' => 'Not/AZone',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/api/v1/admin/work-locations', [
            'code' => 'branch',
            'name' => 'Branch',
            'address' => '2 Example Street',
            'latitude' => -6.3,
            'longitude' => 106.9,
            'radius_meters' => 50,
            'timezone' => 'Asia/Makassar',
            'remote_address' => 'Remote optional',
        ])->assertCreated()->assertJsonPath('data.code', 'BRANCH');

        $this->actingAs($admin)->deleteJson('/api/v1/admin/departments/'.$department)->assertOk();
        $this->actingAs($admin)->postJson('/api/v1/admin/departments', [
            'code' => 'FIN',
            'name' => 'Finance again',
        ])->assertCreated();
    }

    public function test_emp_t04_work_location_timezone_is_locked_after_use(): void
    {
        $admin = $this->user('super_admin');
        $location = WorkLocation::query()->create([
            'code' => 'SITE-2',
            'name' => 'Site 2',
            'address' => '3 Example Street',
            'latitude' => -6.4,
            'longitude' => 107.0,
            'radius_meters' => 100,
            'timezone' => 'Asia/Jakarta',
            'active' => true,
        ]);

        $this->actingAs($admin)->patchJson('/api/v1/admin/work-locations/'.$location->id, [
            'timezone' => 'Asia/Makassar',
        ])->assertOk();

        $employee = $this->employeeProfile('EMP-300', '2026-01-01');
        $employment = EmployeeEmployment::query()->create([
            'employee_id' => $employee->id,
            'employment_type' => 'permanent',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        EmployeeAssignment::query()->create([
            'employee_id' => $employee->id,
            'employee_employment_id' => $employment->id,
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'work_location_id' => $location->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $this->actingAs($admin)->patchJson('/api/v1/admin/work-locations/'.$location->id, [
            'timezone' => 'Asia/Jayapura',
        ])->assertStatus(422);

        $this->assertSame('Asia/Makassar', $location->fresh()->timezone);
    }

    public function test_emp_t11_organization_delete_is_blocked_by_an_active_or_future_assignment(): void
    {
        $admin = $this->user('super_admin');
        $department = Department::query()->create(['code' => 'FUT', 'name' => 'Future']);
        $position = Position::query()->create(['code' => 'FUTPOS', 'name' => 'Future position']);
        $location = WorkLocation::query()->create([
            'code' => 'FUTLOC',
            'name' => 'Future location',
            'address' => '4 Example Street',
            'latitude' => -6.4,
            'longitude' => 107.0,
            'radius_meters' => 100,
            'timezone' => 'Asia/Jakarta',
            'active' => true,
        ]);

        $employee = $this->employeeProfile('EMP-301', '2026-01-01');
        $employment = EmployeeEmployment::query()->create([
            'employee_id' => $employee->id,
            'employment_type' => 'permanent',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id,
            'employee_employment_id' => $employment->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'work_location_id' => $location->id,
            'effective_from' => '2026-08-01',
            'effective_to' => null,
        ]);

        $this->actingAs($admin)->deleteJson('/api/v1/admin/departments/'.$department->id)->assertStatus(422);
        $this->actingAs($admin)->deleteJson('/api/v1/admin/positions/'.$position->id)->assertStatus(422);
        $this->actingAs($admin)->deleteJson('/api/v1/admin/work-locations/'.$location->id)->assertStatus(422);

        $assignment->forceFill(['canceled_at' => now(), 'canceled_by' => $admin->id])->save();

        $this->actingAs($admin)->deleteJson('/api/v1/admin/departments/'.$department->id)->assertOk();
        $this->actingAs($admin)->deleteJson('/api/v1/admin/positions/'.$position->id)->assertOk();
        $this->actingAs($admin)->deleteJson('/api/v1/admin/work-locations/'.$location->id)->assertOk();

        $this->actingAs($admin)->postJson('/api/v1/admin/departments/'.$department->id.'/restore')->assertOk();
        $this->actingAs($admin)->postJson('/api/v1/admin/positions/'.$position->id.'/restore')->assertOk();
        $this->actingAs($admin)->postJson('/api/v1/admin/work-locations/'.$location->id.'/restore')
            ->assertOk()
            ->assertJsonPath('data.active', true);
    }
}
