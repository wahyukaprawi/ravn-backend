<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkCalendarDay;
use App\Models\WorkflowDefinition;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

abstract class LeaveTestCase extends TestCase
{
    protected function password(): string
    {
        return 'correct-password-123';
    }

    protected function calendar(string $timezone = 'Asia/Jakarta'): WorkCalendar
    {
        return WorkCalendar::query()->create([
            'name' => 'Default',
            'timezone' => $timezone,
            'active' => true,
        ]);
    }

    protected function holiday(WorkCalendar $calendar, string $date): WorkCalendarDay
    {
        return WorkCalendarDay::query()->create([
            'work_calendar_id' => $calendar->getKey(),
            'date' => $date,
            'type' => 'holiday',
            'name' => 'Public holiday',
        ]);
    }

    protected function leaveType(string $code = 'annual', string $name = 'Annual Leave'): LeaveType
    {
        return LeaveType::query()->create([
            'code' => $code,
            'name' => $name,
            'requires_approval' => true,
            'active' => true,
        ]);
    }

    protected function policy(LeaveType $type, int $entitlementDays = 12): LeavePolicy
    {
        return LeavePolicy::query()->create([
            'leave_type_id' => $type->getKey(),
            'name' => 'Default policy',
            'annual_entitlement_days' => $entitlementDays,
            'active' => true,
            'effective_from' => '2020-01-01',
        ]);
    }

    protected function userWithRole(string $role = 'employee', ?User $manager = null): User
    {
        return User::factory()->create([
            'password' => Hash::make($this->password()),
            'manager_id' => $manager?->getKey(),
        ])->assignRole($role);
    }

    protected function login(User $user): string
    {
        $this->freshRequest();

        $payload = [
            'email' => $user->email,
            'password' => $this->password(),
            'device_name' => 'phpunit',
        ];

        if ($user->hasRole('super_admin') || $user->hasRole('hr_admin')) {
            $method = $user->mfaMethods()->where('type', 'totp')->first();

            if ($method === null) {
                $method = $user->mfaMethods()->create([
                    'type' => 'totp',
                    'secret' => app(Google2FA::class)->generateSecretKey(),
                    'confirmed_at' => now(),
                ]);
            }

            if ($method->last_used_timestep !== null) {
                $method->forceFill(['last_used_timestep' => null])->save();
            }

            $payload['code'] = app(Google2FA::class)->getCurrentOtp($method->secret);
        }

        $response = $this->postJson('/api/v1/auth/login', $payload);

        $response->assertOk();

        return $response->json('token');
    }

    protected function loginAs(User $user): static
    {
        $token = $this->login($user);

        return $this->freshRequest()->withToken($token);
    }

    protected function scenario(int $entitlement = 12, string $timezone = 'Asia/Jakarta'): array
    {
        $this->calendar($timezone);

        $type = $this->leaveType();
        $this->policy($type, $entitlement);

        $manager = $this->userWithRole('manager');
        $employee = $this->userWithRole('employee', $manager);

        $this->grantBalance($employee, $type, 2027, $entitlement);

        return [$employee, $manager, $type];
    }

    protected function submitRequest(User $employee, LeaveType $type, string $start, string $end, array $sheets = []): TestResponse
    {
        $headers = $sheets['headers'] ?? [];

        return $this->loginAs($employee)->postJson('/api/v1/leave/requests', array_filter([
            'leave_type_id' => $type->getKey(),
            'start_date' => $start,
            'end_date' => $end,
            'reason' => $sheets['reason'] ?? null,
        ]), $headers);
    }

    protected function grantBalance(User $user, LeaveType $type, int $year, float $entitled): LeaveBalance
    {
        return LeaveBalance::query()->create([
            'user_id' => $user->getKey(),
            'leave_type_id' => $type->getKey(),
            'year' => $year,
            'entitled' => $entitled,
            'used' => 0,
            'reserved' => 0,
            'adjusted' => 0,
        ]);
    }

    protected function balance(User $user, LeaveType $type, int $year): LeaveBalance
    {
        return LeaveBalance::query()
            ->where('user_id', $user->getKey())
            ->where('leave_type_id', $type->getKey())
            ->where('year', $year)
            ->firstOrFail();
    }

    protected function approvalDefinition(): WorkflowDefinition
    {
        return WorkflowDefinition::query()->where('code', 'leave_approval')->firstOrFail();
    }
}
