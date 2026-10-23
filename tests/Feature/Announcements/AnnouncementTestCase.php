<?php

namespace Tests\Feature\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementAudience;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Support\Carbon;
use Tests\TestCase;

abstract class AnnouncementTestCase extends TestCase
{
    protected Department $department;

    protected Department $otherDepartment;

    protected Position $position;

    protected WorkLocation $location;

    protected WorkLocation $otherLocation;

    protected User $hr;

    protected User $manager;

    protected User $employee;

    protected User $other;

    protected Employee $managerProfile;

    protected Employee $employeeProfile;

    protected Employee $otherProfile;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

        $this->department = Department::query()->create([
            'code' => 'ENG',
            'name' => 'Engineering',
        ]);

        $this->otherDepartment = Department::query()->create([
            'code' => 'FIN',
            'name' => 'Finance',
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

        $this->otherLocation = WorkLocation::query()->create([
            'code' => 'BRANCH',
            'name' => 'Branch Office',
            'address' => '2 Example Street',
            'latitude' => -6.3,
            'longitude' => 106.9,
            'radius_meters' => 100,
            'timezone' => 'Asia/Jakarta',
            'active' => true,
        ]);

        $this->hr = $this->user('hr_admin');
        $this->manager = $this->user('manager');
        $this->employee = $this->user('employee');
        $this->other = $this->user('employee');

        $this->managerProfile = $this->staff(
            $this->manager,
            'EMP-MGR',
            null,
            $this->department,
            $this->location,
        );

        $this->employeeProfile = $this->staff(
            $this->employee,
            'EMP-001',
            $this->manager,
            $this->department,
            $this->location,
        );

        $this->otherProfile = $this->staff(
            $this->other,
            'EMP-002',
            null,
            $this->otherDepartment,
            $this->otherLocation,
        );
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
        ?Department $department = null,
        ?WorkLocation $location = null,
    ): Employee {
        $employee = Employee::query()->create([
            'employee_no' => $employeeNo,
            'user_id' => $user->getKey(),
            'first_name' => 'Test',
            'last_name' => $employeeNo,
            'work_email' => mb_strtolower($employeeNo).'@example.test',
            'hire_date' => '2024-01-01',
        ]);

        $employment = EmployeeEmployment::query()->create([
            'employee_id' => $employee->getKey(),
            'employment_type' => EmployeeEmployment::TYPE_PERMANENT,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        EmployeeAssignment::query()->create([
            'employee_id' => $employee->getKey(),
            'employee_employment_id' => $employment->getKey(),
            'department_id' => ($department ?? $this->department)->getKey(),
            'position_id' => $this->position->getKey(),
            'manager_id' => $manager?->getKey(),
            'work_location_id' => ($location ?? $this->location)->getKey(),
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        return $employee;
    }

    protected function tokenFor(User $user): string
    {
        return $user->createToken('phpunit')->plainTextToken;
    }

    protected function asUser(User $user): static
    {
        return $this->freshRequest()->withToken($this->tokenFor($user));
    }

    protected function makeAnnouncement(
        string $status = Announcement::STATUS_DRAFT,
        array $audiences = [['type' => AnnouncementAudience::TYPE_ALL_EMPLOYEES, 'id' => null]],
        array $attributes = [],
    ): Announcement {
        $announcement = Announcement::query()->create(array_merge([
            'title' => 'Policy update',
            'body' => 'The employee handbook has been updated.',
            'status' => $status,
            'author_id' => $this->hr->getKey(),
            'published_at' => $status === Announcement::STATUS_PUBLISHED ? now() : null,
            'archived_at' => $status === Announcement::STATUS_ARCHIVED ? now() : null,
        ], $attributes));

        foreach ($audiences as $audience) {
            $announcement->audiences()->create([
                'audience_type' => $audience['type'],
                'audience_id' => $audience['type'] === AnnouncementAudience::TYPE_ALL_EMPLOYEES
                    ? 0
                    : $audience['id'],
            ]);
        }

        return $announcement->fresh('audiences');
    }

    protected function payload(array $audiences = [['type' => AnnouncementAudience::TYPE_ALL_EMPLOYEES, 'id' => null]], array $overrides = []): array
    {
        return array_merge([
            'title' => 'Policy update',
            'body' => 'The employee handbook has been updated.',
            'audiences' => $audiences,
        ], $overrides);
    }
}
