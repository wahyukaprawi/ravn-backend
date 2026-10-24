<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\AnnouncementAudience;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkLocation;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public const PASSWORD = 'RavnDemo!2026';

    public function run(): void
    {
        $this->call([
            RbacSeeder::class,
            WorkflowSeeder::class,
            ReportSeeder::class,
        ]);

        $superAdmin = $this->user('Ravn Super Admin', 'superadmin@ravn.test', 'super_admin');
        $hr = $this->user('Ravn HR Admin', 'hr@ravn.test', 'hr_admin');
        $manager = $this->user('Ravn Manager', 'manager@ravn.test', 'manager', $hr);
        $employee = $this->user('Ravn Employee', 'employee@ravn.test', 'employee', $manager);

        $department = Department::query()->updateOrCreate(
            ['code' => 'ENG'],
            ['name' => 'Engineering', 'description' => 'Synthetic engineering department'],
        );

        $position = Position::query()->updateOrCreate(
            ['code' => 'DEV'],
            ['name' => 'Software Engineer', 'description' => 'Synthetic engineering position'],
        );

        $location = WorkLocation::query()->updateOrCreate(
            ['code' => 'HQ'],
            [
                'name' => 'Ravn Demo Head Office',
                'address' => 'Synthetic address, Jakarta',
                'remote_address' => null,
                'latitude' => -6.2088000,
                'longitude' => 106.8456000,
                'radius_meters' => 5000,
                'timezone' => 'Asia/Jakarta',
                'active' => true,
            ],
        );

        WorkCalendar::query()->updateOrCreate(
            ['name' => 'Ravn Demo Calendar'],
            ['timezone' => 'Asia/Jakarta', 'active' => true],
        );

        $profiles = [
            [$superAdmin, 'EMP-SA', null],
            [$hr, 'EMP-HR', $superAdmin],
            [$manager, 'EMP-MGR', $hr],
            [$employee, 'EMP-001', $manager],
        ];

        foreach ($profiles as [$user, $employeeNo, $reportingTo]) {
            $this->profile($user, $employeeNo, $reportingTo, $department, $position, $location);
            $this->schedule($user, $location);
        }

        $annual = LeaveType::query()->updateOrCreate(
            ['code' => 'annual'],
            ['name' => 'Annual Leave', 'requires_approval' => true, 'active' => true],
        );

        $sick = LeaveType::query()->updateOrCreate(
            ['code' => 'sick'],
            ['name' => 'Sick Leave', 'requires_approval' => true, 'active' => true],
        );

        $this->policy($annual, 'Ravn Demo Annual Policy', 12);
        $this->policy($sick, 'Ravn Demo Sick Policy', 5);

        foreach ([$superAdmin, $hr, $manager, $employee] as $user) {
            $this->balance($user, $annual, 12);
            $this->balance($user, $sick, 5);
        }

        $announcement = Announcement::query()->updateOrCreate(
            ['title' => 'Welcome to Ravn'],
            [
                'body' => 'This is a synthetic announcement for local and device testing.',
                'status' => Announcement::STATUS_PUBLISHED,
                'author_id' => $hr->getKey(),
                'scheduled_at' => null,
                'published_at' => now(),
                'archived_at' => null,
            ],
        );

        $announcement->audiences()->updateOrCreate(
            [
                'audience_type' => AnnouncementAudience::TYPE_ALL_EMPLOYEES,
                'audience_id' => 0,
            ],
            [],
        );

        if (isset($this->command)) {
            $this->command->info('Demo accounts ready. Password: '.self::PASSWORD);
            $this->command->info('superadmin@ravn.test, hr@ravn.test, manager@ravn.test, employee@ravn.test');
        }
    }

    protected function user(string $name, string $email, string $role, ?User $manager = null): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => self::PASSWORD,
                'status' => User::STATUS_ACTIVE,
                'activated_at' => now(),
                'deactivated_at' => null,
                'deactivated_by' => null,
                'manager_id' => $manager?->getKey(),
            ],
        );

        $user->forceFill(['email_verified_at' => now()])->save();
        $user->syncRoles([$role]);

        return $user;
    }

    protected function profile(
        User $user,
        string $employeeNo,
        ?User $manager,
        Department $department,
        Position $position,
        WorkLocation $location,
    ): void {
        $employee = Employee::query()->updateOrCreate(
            ['employee_no' => $employeeNo],
            [
                'user_id' => $user->getKey(),
                'first_name' => $user->name,
                'last_name' => null,
                'phone' => null,
                'work_email' => $user->email,
                'hire_date' => '2024-01-01',
            ],
        );

        $employment = EmployeeEmployment::query()->updateOrCreate(
            [
                'employee_id' => $employee->getKey(),
                'effective_from' => '2024-01-01',
            ],
            [
                'employment_type' => EmployeeEmployment::TYPE_PERMANENT,
                'effective_to' => null,
            ],
        );

        EmployeeAssignment::query()->updateOrCreate(
            [
                'employee_id' => $employee->getKey(),
                'effective_from' => '2024-01-01',
            ],
            [
                'employee_employment_id' => $employment->getKey(),
                'department_id' => $department->getKey(),
                'position_id' => $position->getKey(),
                'manager_id' => $manager?->getKey(),
                'work_location_id' => $location->getKey(),
                'effective_to' => null,
                'replaced_assignment_id' => null,
                'canceled_at' => null,
                'canceled_by' => null,
            ],
        );
    }

    protected function schedule(User $user, WorkLocation $location): void
    {
        Schedule::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'effective_from' => '2024-01-01',
            ],
            [
                'work_location_id' => $location->getKey(),
                'name' => 'Ravn Demo Daily Shift',
                'effective_to' => null,
                'days' => [1, 2, 3, 4, 5, 6, 7],
                'start_time' => '00:00',
                'end_time' => '23:59',
                'overnight' => false,
                'late_grace_minutes' => 0,
                'early_departure_grace_minutes' => 0,
                'active' => true,
            ],
        );
    }

    protected function policy(LeaveType $type, string $name, int $entitlement): void
    {
        LeavePolicy::query()->updateOrCreate(
            [
                'leave_type_id' => $type->getKey(),
                'name' => $name,
            ],
            [
                'annual_entitlement_days' => $entitlement,
                'submission_deadline_days_before' => null,
                'active' => true,
                'effective_from' => '2020-01-01',
                'effective_to' => null,
            ],
        );
    }

    protected function balance(User $user, LeaveType $type, int $entitlement): void
    {
        foreach ([now()->year, now()->year + 1] as $year) {
            LeaveBalance::query()->firstOrCreate(
                [
                    'user_id' => $user->getKey(),
                    'leave_type_id' => $type->getKey(),
                    'year' => $year,
                ],
                [
                    'entitled' => $entitlement,
                    'used' => 0,
                    'reserved' => 0,
                    'adjusted' => 0,
                ],
            );
        }
    }
}
