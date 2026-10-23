<?php

namespace Tests\Feature\Reporting;

use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WorkLocation;
use Database\Seeders\ReportSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class ReportTestCase extends TestCase
{
    protected User $hr;

    protected User $manager;

    protected User $otherManager;

    protected User $employee;

    protected User $teammate;

    protected User $outsider;

    protected User $superAdmin;

    protected Department $department;

    protected Position $position;

    protected WorkLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00', 'Asia/Jakarta'));

        $this->seed(ReportSeeder::class);

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

        $this->otherManager = $this->user('manager');
        $this->manager = $this->user('manager');
        $this->hr = $this->user('hr_admin');
        $this->superAdmin = $this->user('super_admin');

        $this->employee = $this->employeeUser('EMP-001', $this->manager);
        $this->teammate = $this->employeeUser('EMP-002', $this->manager);
        $this->outsider = $this->employeeUser('EMP-003', $this->otherManager);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function user(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    protected function employeeUser(string $employeeNo, ?User $manager): User
    {
        $user = $this->user('employee');

        $profile = Employee::query()->create([
            'employee_no' => $employeeNo,
            'user_id' => $user->getKey(),
            'first_name' => 'Test',
            'last_name' => $employeeNo,
            'work_email' => strtolower($employeeNo).'@example.test',
            'hire_date' => '2024-01-01',
        ]);

        $employment = EmployeeEmployment::query()->create([
            'employee_id' => $profile->getKey(),
            'employment_type' => EmployeeEmployment::TYPE_PERMANENT,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        EmployeeAssignment::query()->create([
            'employee_id' => $profile->getKey(),
            'employee_employment_id' => $employment->getKey(),
            'department_id' => $this->department->getKey(),
            'position_id' => $this->position->getKey(),
            'manager_id' => $manager?->getKey(),
            'work_location_id' => $this->location->getKey(),
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        return $user;
    }

    protected function scheduleFor(User $user): Schedule
    {
        return Schedule::query()->create([
            'user_id' => $user->getKey(),
            'work_location_id' => $this->location->getKey(),
            'name' => 'Default day shift',
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'days' => [1, 2, 3, 4, 5],
            'start_time' => '09:00',
            'end_time' => '17:00',
            'overnight' => false,
            'active' => true,
        ]);
    }

    protected function addSession(User $user, array $overrides = []): AttendanceSession
    {
        return AttendanceSession::query()->create(array_merge([
            'user_id' => $user->getKey(),
            'work_date' => '2026-06-15',
            'status' => AttendanceSession::STATUS_CLOSED,
            'opened_at' => Carbon::parse('2026-06-15 09:00:00', 'Asia/Jakarta'),
            'closed_at' => Carbon::parse('2026-06-15 17:00:00', 'Asia/Jakarta'),
            'timezone' => 'Asia/Jakarta',
            'scheduled_start_at' => Carbon::parse('2026-06-15 09:00:00', 'Asia/Jakarta'),
            'scheduled_end_at' => Carbon::parse('2026-06-15 17:00:00', 'Asia/Jakarta'),
            'late_minutes' => 0,
            'early_departure_minutes' => 0,
            'flags' => [],
        ], $overrides));
    }

    protected function tokenFor(User $user): string
    {
        return $user->createToken('phpunit')->plainTextToken;
    }

    protected function asUser(User $user): static
    {
        return $this->freshRequest()->withToken($this->tokenFor($user));
    }

    protected function runReport(User $actor, string $code, array $filters = []): TestResponse
    {
        return $this->asUser($actor)->getJson('/api/v1/reports/'.$code.'?'.http_build_query($filters));
    }

    protected function punch(User $user): array
    {
        $challenge = $this->asUser($user)
            ->postJson('/api/v1/attendance/challenges', ['device_id' => 'device-1'])
            ->assertCreated()
            ->json('data.challenge_id');

        return [
            'latitude' => (float) $this->location->latitude,
            'longitude' => (float) $this->location->longitude,
            'accuracy_meters' => 10,
            'mock_location_detected' => false,
            'device_id' => 'device-1',
            'face_challenge_id' => $challenge,
            'face_verified' => true,
            'source' => 'app',
        ];
    }

    protected function checkIn(User $user, string $key = 'check-in-key'): TestResponse
    {
        return $this->asUser($user)->postJson(
            '/api/v1/attendance/check-in',
            $this->punch($user),
            ['Idempotency-Key' => $key],
        );
    }

    protected function checkOut(User $user, string $key = 'check-out-key'): TestResponse
    {
        return $this->asUser($user)->postJson(
            '/api/v1/attendance/check-out',
            $this->punch($user),
            ['Idempotency-Key' => $key],
        );
    }
}
