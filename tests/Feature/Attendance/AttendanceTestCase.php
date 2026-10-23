<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class AttendanceTestCase extends TestCase
{
    protected Department $department;

    protected Position $position;

    protected WorkLocation $location;

    protected User $employee;

    protected User $manager;

    protected User $hr;

    protected Employee $profile;

    protected Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00', 'Asia/Jakarta'));

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

        $this->manager = $this->user('manager');
        $this->employee = $this->user('employee');
        $this->hr = $this->user('hr_admin');

        $this->profile = Employee::query()->create([
            'employee_no' => 'EMP-001',
            'user_id' => $this->employee->getKey(),
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'work_email' => 'employee@example.test',
            'hire_date' => '2024-01-01',
        ]);

        $employment = EmployeeEmployment::query()->create([
            'employee_id' => $this->profile->getKey(),
            'employment_type' => EmployeeEmployment::TYPE_PERMANENT,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        EmployeeAssignment::query()->create([
            'employee_id' => $this->profile->getKey(),
            'employee_employment_id' => $employment->getKey(),
            'department_id' => $this->department->getKey(),
            'position_id' => $this->position->getKey(),
            'manager_id' => $this->manager->getKey(),
            'work_location_id' => $this->location->getKey(),
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ]);

        $this->schedule = Schedule::query()->create([
            'user_id' => $this->employee->getKey(),
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function user(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    protected function tokenFor(User $user, bool $freshMfa = false): string
    {
        $newToken = $user->createToken('phpunit');

        if ($freshMfa) {
            $newToken->accessToken->forceFill(['mfa_verified_at' => now()])->save();
        }

        return $newToken->plainTextToken;
    }

    protected function asUser(User $user, bool $freshMfa = false): static
    {
        return $this->freshRequest()->withToken($this->tokenFor($user, $freshMfa));
    }

    protected function challenge(User $user, string $deviceId = 'device-1'): string
    {
        $response = $this->asUser($user)->postJson('/api/v1/attendance/challenges', [
            'device_id' => $deviceId,
        ]);

        $response->assertCreated();

        return $response->json('data.challenge_id');
    }

    protected function punch(User $user, array $overrides = []): array
    {
        return array_merge([
            'latitude' => (float) $this->location->latitude,
            'longitude' => (float) $this->location->longitude,
            'accuracy_meters' => 10,
            'mock_location_detected' => false,
            'device_integrity' => ['platform' => 'ios', 'signal' => 'test'],
            'device_id' => 'device-1',
            'face_challenge_id' => $this->challenge($user),
            'face_verified' => true,
            'source' => 'app',
        ], $overrides);
    }

    protected function checkIn(User $user, array $overrides = [], string $key = 'check-in-key'): TestResponse
    {
        return $this->asUser($user)->postJson(
            '/api/v1/attendance/check-in',
            $this->punch($user, $overrides),
            ['Idempotency-Key' => $key],
        );
    }

    protected function checkOut(User $user, array $overrides = [], string $key = 'check-out-key'): TestResponse
    {
        return $this->asUser($user)->postJson(
            '/api/v1/attendance/check-out',
            $this->punch($user, $overrides),
            ['Idempotency-Key' => $key],
        );
    }

    protected function sessionFor(User $user): int
    {
        $response = $this->checkIn($user)->assertCreated();

        return (int) $response->json('data.session.id');
    }

    protected function createCorrection(User $user, int $sessionId, array $changes, ?UploadedFile $evidence = null): TestResponse
    {
        $payload = [
            'attendance_session_id' => $sessionId,
            'changes' => $changes,
            'reason' => 'Correcting the recorded attendance time.',
        ];

        if ($evidence !== null) {
            $payload['evidence'] = $evidence;
        }

        return $this->asUser($user)->post('/api/v1/attendance/corrections', $payload, [
            'Accept' => 'application/json',
        ]);
    }

    protected function submitCorrection(User $user, int $id): TestResponse
    {
        return $this->asUser($user)->postJson('/api/v1/attendance/corrections/'.$id.'/submit');
    }

    protected function approveCorrection(User $user, int $id): TestResponse
    {
        return $this->asUser($user, true)->postJson('/api/v1/attendance/corrections/'.$id.'/approve');
    }

    protected function executeCorrection(User $user, int $id): TestResponse
    {
        return $this->asUser($user, true)->postJson('/api/v1/attendance/corrections/'.$id.'/execute');
    }
}
