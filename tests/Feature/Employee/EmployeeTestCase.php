<?php

namespace Tests\Feature\Employee;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Support\Carbon;
use Tests\TestCase;

abstract class EmployeeTestCase extends TestCase
{
    protected Department $department;

    protected Position $position;

    protected WorkLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

        $this->department = Department::query()->create([
            'code' => 'ENG',
            'name' => 'Engineering',
        ]);

        $this->position = Position::query()->create([
            'code' => 'DEV',
            'name' => 'Developer',
        ]);

        $this->location = WorkLocation::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'address' => '1 Example Street',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 100,
            'timezone' => 'Asia/Jakarta',
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function user(string $role = 'employee'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    protected function staff(
        User $user,
        string $employeeNo,
        ?User $manager = null,
        string $from = '2024-01-01',
        ?string $to = null,
        ?Department $department = null,
        ?Position $position = null,
        ?WorkLocation $location = null,
    ): Employee {
        $employee = Employee::query()->create([
            'employee_no' => $employeeNo,
            'user_id' => $user->getKey(),
            'first_name' => 'Test',
            'last_name' => $employeeNo,
            'work_email' => mb_strtolower($employeeNo).'@example.test',
            'hire_date' => $from,
        ]);

        $employment = EmployeeEmployment::query()->create([
            'employee_id' => $employee->getKey(),
            'employment_type' => EmployeeEmployment::TYPE_PERMANENT,
            'effective_from' => $from,
            'effective_to' => $to,
        ]);

        EmployeeAssignment::query()->create([
            'employee_id' => $employee->getKey(),
            'employee_employment_id' => $employment->getKey(),
            'department_id' => ($department ?? $this->department)->getKey(),
            'position_id' => ($position ?? $this->position)->getKey(),
            'manager_id' => $manager?->getKey(),
            'work_location_id' => ($location ?? $this->location)->getKey(),
            'effective_from' => $from,
            'effective_to' => $to,
        ]);

        return $employee;
    }

    protected function employeeProfile(string $employeeNo = 'EMP-001', string $hireDate = '2024-01-01', ?User $user = null): Employee
    {
        return Employee::query()->create([
            'employee_no' => $employeeNo,
            'user_id' => $user?->getKey(),
            'first_name' => 'Test',
            'last_name' => $employeeNo,
            'work_email' => mb_strtolower($employeeNo).'@example.test',
            'hire_date' => $hireDate,
        ]);
    }

    protected function freshMfaToken(User $user): string
    {
        $newToken = $user->createToken('phpunit');
        $newToken->accessToken->forceFill(['mfa_verified_at' => now()])->save();

        return $newToken->plainTextToken;
    }
}
