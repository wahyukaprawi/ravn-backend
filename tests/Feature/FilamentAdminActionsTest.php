<?php

namespace Tests\Feature;

use App\Filament\Auth\RavnAppAuthentication;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Resources\Departments\Pages\CreateDepartment;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\LeaveBalances\Pages\ListLeaveBalances;
use App\Filament\Resources\LeavePolicies\Pages\CreateLeavePolicy;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\LeaveTypes\Pages\CreateLeaveType;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Schedules\Pages\CreateSchedule;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\WorkCalendars\Pages\CreateWorkCalendar;
use App\Models\Announcement;
use App\Models\AnnouncementAudience;
use App\Models\AuditLog;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkLocation;
use App\Services\AnnouncementService;
use App\Services\FilamentStepUpService;
use App\Services\LeaveService;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentAdminActionsTest extends TestCase
{
    protected string $mfaSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->actingAs(User::query()->where('email', 'superadmin@ravn.test')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function enrollAdminMfa(): void
    {
        $this->mfaSecret = app(Google2FA::class)->generateSecretKey();

        auth()->user()->mfaMethods()->create([
            'type' => 'totp',
            'secret' => $this->mfaSecret,
            'confirmed_at' => now(),
        ]);
    }

    protected function mfaCode(): string
    {
        return app(Google2FA::class)->getCurrentOtp($this->mfaSecret);
    }

    protected function userWithMfa(): User
    {
        $user = User::factory()->create();

        $user->mfaMethods()->create([
            'type' => 'totp',
            'secret' => app(Google2FA::class)->generateSecretKey(),
            'confirmed_at' => now(),
        ]);

        return $user;
    }

    public function test_department_creation_uses_the_domain_service(): void
    {
        Livewire::test(CreateDepartment::class)
            ->fillForm([
                'code' => 'OPS',
                'name' => 'Operations',
                'description' => 'Synthetic operations department',
            ])
            ->assertFormSet([
                'code' => 'OPS',
                'name' => 'Operations',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('departments', [
            'code' => 'ops',
            'name' => 'Operations',
        ]);
    }

    public function test_user_invitation_creates_a_pending_account(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Synthetic Invitee',
                'email' => 'invitee@ravn.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'invitee@ravn.test',
            'status' => User::STATUS_PENDING_ACTIVATION,
        ]);
    }

    public function test_employee_creation_uses_the_domain_service(): void
    {
        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'employee_no' => 'EMP-NEW-001',
                'first_name' => 'Synthetic',
                'last_name' => 'Newcomer',
                'work_email' => 'newcomer@ravn.test',
                'hire_date' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('employees', [
            'employee_no' => 'EMP-NEW-001',
            'first_name' => 'Synthetic',
            'last_name' => 'Newcomer',
        ]);
    }

    public function test_schedule_creation_uses_the_domain_service(): void
    {
        $user = User::factory()->create();
        $location = WorkLocation::query()->where('active', true)->firstOrFail();

        Livewire::test(CreateSchedule::class)
            ->fillForm([
                'user_id' => $user->id,
                'work_location_id' => $location->id,
                'name' => 'Synthetic Day Shift',
                'active' => true,
                'effective_from' => now()->addMonth()->toDateString(),
                'days' => [1, 2, 3, 4, 5],
                'start_time' => '09:00',
                'end_time' => '18:00',
                'late_grace_minutes' => 10,
                'early_departure_grace_minutes' => 5,
                'overnight' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $schedule = Schedule::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('Synthetic Day Shift', $schedule->name);
        $this->assertSame([1, 2, 3, 4, 5], array_map('intval', $schedule->days));
    }

    public function test_announcement_creation_normalizes_audiences(): void
    {
        Livewire::test(CreateAnnouncement::class)
            ->fillForm([
                'title' => 'Synthetic announcement',
                'body' => 'Synthetic announcement body.',
                'audiences' => [
                    [
                        'audience_type' => AnnouncementAudience::TYPE_ALL_EMPLOYEES,
                        'audience_id' => null,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $announcement = Announcement::query()
            ->where('title', 'Synthetic announcement')
            ->firstOrFail();

        $this->assertSame(Announcement::STATUS_DRAFT, $announcement->status);
        $this->assertSame(
            AnnouncementAudience::TYPE_ALL_EMPLOYEES,
            $announcement->audiences()->firstOrFail()->audience_type,
        );
    }

    public function test_work_calendar_creation_saves_holiday_exceptions(): void
    {
        Livewire::test(CreateWorkCalendar::class)
            ->fillForm([
                'name' => 'Synthetic Calendar',
                'timezone' => 'Asia/Manila',
                'active' => true,
                'days' => [
                    [
                        'date' => now()->addMonth()->toDateString(),
                        'type' => 'holiday',
                        'name' => 'Synthetic Holiday',
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $calendar = WorkCalendar::query()
            ->where('name', 'Synthetic Calendar')
            ->firstOrFail();

        $this->assertSame(1, $calendar->days()->count());
        $this->assertDatabaseHas('work_calendar_days', [
            'work_calendar_id' => $calendar->id,
            'type' => 'holiday',
            'name' => 'Synthetic Holiday',
        ]);
    }

    public function test_leave_type_and_policy_creation_are_audited(): void
    {
        Livewire::test(CreateLeaveType::class)
            ->fillForm([
                'code' => 'study',
                'name' => 'Study Leave',
                'requires_approval' => true,
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $type = LeaveType::query()->where('code', 'study')->firstOrFail();

        Livewire::test(CreateLeavePolicy::class)
            ->fillForm([
                'leave_type_id' => $type->id,
                'name' => 'Synthetic Study Policy',
                'annual_entitlement_days' => 3,
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $policy = LeavePolicy::query()
            ->where('name', 'Synthetic Study Policy')
            ->firstOrFail();

        $this->assertSame($type->id, $policy->leave_type_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.type.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.policy.created']);
    }

    public function test_leave_request_can_be_approved_from_the_admin_table(): void
    {
        $manager = User::query()->where('email', 'manager@ravn.test')->firstOrFail();
        $hr = User::query()->where('email', 'hr@ravn.test')->firstOrFail();

        $request = app(LeaveService::class)->submit($manager, [
            'leave_type_id' => LeaveType::query()->where('code', 'annual')->firstOrFail()->id,
            'start_date' => now()->next('Monday')->toDateString(),
            'end_date' => now()->next('Monday')->toDateString(),
            'reason' => 'Synthetic approval test',
        ]);

        $this->actingAs($hr);

        Livewire::test(ListLeaveRequests::class)
            ->callTableAction('approve', $request)
            ->assertHasNoErrors();

        Livewire::test(ListLeaveRequests::class)
            ->callTableAction('approve', $request->fresh())
            ->assertHasNoErrors();

        $this->assertSame(LeaveRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    public function test_leave_balance_can_be_adjusted_from_the_admin_table(): void
    {
        $this->enrollAdminMfa();

        $employee = User::query()->where('email', 'employee@ravn.test')->firstOrFail();
        $balance = LeaveBalance::query()
            ->where('user_id', $employee->id)
            ->where('year', now()->year)
            ->firstOrFail();

        $before = (float) $balance->adjusted;

        Livewire::test(ListLeaveBalances::class)
            ->callTableAction('adjust', $balance, data: [
                'amount' => 2,
                'reason' => 'Synthetic adjustment test',
                'mfa_code' => $this->mfaCode(),
            ])
            ->assertHasNoErrors();

        $this->assertSame($before + 2, (float) $balance->fresh()->adjusted);
    }

    public function test_announcement_can_be_published_from_the_admin_table(): void
    {
        $this->enrollAdminMfa();

        $admin = User::query()->where('email', 'superadmin@ravn.test')->firstOrFail();

        $announcement = app(AnnouncementService::class)->create($admin, [
            'title' => 'Synthetic publish test',
            'body' => 'Synthetic body',
            'audiences' => [
                ['type' => AnnouncementAudience::TYPE_ALL_EMPLOYEES, 'id' => 0],
            ],
        ]);

        Livewire::test(ListAnnouncements::class)
            ->callTableAction('publish', $announcement, data: [
                'mfa_code' => $this->mfaCode(),
            ])
            ->assertHasNoErrors();

        $this->assertSame(Announcement::STATUS_PUBLISHED, $announcement->fresh()->status);
    }

    public function test_user_roles_can_be_assigned_from_the_admin_table(): void
    {
        $this->enrollAdminMfa();

        $target = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->callTableAction('assignRoles', $target, data: [
                'roles' => ['manager'],
                'mfa_code' => $this->mfaCode(),
            ])
            ->assertHasNoErrors();

        $this->assertTrue($target->fresh()->hasRole('manager'));
    }

    public function test_mfa_can_be_reset_from_the_admin_table(): void
    {
        $this->enrollAdminMfa();

        $target = User::factory()->create();
        $target->mfaMethods()->create([
            'type' => 'totp',
            'secret' => app(Google2FA::class)->generateSecretKey(),
            'confirmed_at' => now(),
        ]);

        Livewire::test(ListUsers::class)
            ->callTableAction('resetMfa', $target, data: [
                'mfa_code' => $this->mfaCode(),
            ])
            ->assertHasNoErrors();

        $this->assertSame(0, $target->fresh()->mfaMethods()->count());
    }

    public function test_role_permissions_can_be_updated_from_the_edit_form(): void
    {
        $this->enrollAdminMfa();

        $role = Role::query()->where('name', 'hr_admin')->firstOrFail();

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm([
                'permissions' => ['users.view', 'employees.view_any'],
                'mfa_code' => $this->mfaCode(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            ['employees.view_any', 'users.view'],
            $role->fresh()->permissions()->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_sensitive_action_requires_an_authenticator_code(): void
    {
        $this->enrollAdminMfa();

        $target = User::factory()->create();
        $target->mfaMethods()->create([
            'type' => 'totp',
            'secret' => app(Google2FA::class)->generateSecretKey(),
            'confirmed_at' => now(),
        ]);

        Livewire::test(ListUsers::class)
            ->callTableAction('resetMfa', $target)
            ->assertHasFormErrors(['mfa_code']);

        $this->assertSame(1, $target->fresh()->mfaMethods()->count());
    }

    public function test_sensitive_action_rejects_an_invalid_authenticator_code(): void
    {
        $this->enrollAdminMfa();

        $target = User::factory()->create();
        $target->mfaMethods()->create([
            'type' => 'totp',
            'secret' => app(Google2FA::class)->generateSecretKey(),
            'confirmed_at' => now(),
        ]);

        Livewire::test(ListUsers::class)
            ->callTableAction('resetMfa', $target, data: [
                'mfa_code' => '000000',
            ])
            ->assertHasFormErrors(['mfa_code']);

        $this->assertSame(1, $target->fresh()->mfaMethods()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'mfa.step_up',
            'outcome' => AuditLog::OUTCOME_FAILURE,
        ]);
    }

    public function test_role_permission_changes_require_an_authenticator_code(): void
    {
        $this->enrollAdminMfa();

        $role = Role::query()->where('name', 'hr_admin')->firstOrFail();
        $before = $role->permissions()->orderBy('name')->pluck('name')->all();

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm([
                'permissions' => ['users.view'],
            ])
            ->call('save')
            ->assertHasFormErrors(['mfa_code']);

        $this->assertSame(
            $before,
            $role->fresh()->permissions()->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_step_up_audit_does_not_store_the_authenticator_code(): void
    {
        $this->enrollAdminMfa();

        $target = User::factory()->create();
        $target->mfaMethods()->create([
            'type' => 'totp',
            'secret' => app(Google2FA::class)->generateSecretKey(),
            'confirmed_at' => now(),
        ]);

        $code = $this->mfaCode();

        Livewire::test(ListUsers::class)
            ->callTableAction('resetMfa', $target, data: [
                'mfa_code' => $code,
            ])
            ->assertHasNoErrors();

        $log = AuditLog::query()->where('action', 'mfa.step_up')->latest('id')->firstOrFail();

        $this->assertSame('filament', $log->after['surface']);
        $this->assertStringNotContainsString($code, json_encode([$log->before, $log->after]));
    }

    public function test_step_up_is_reused_within_the_freshness_window(): void
    {
        $this->enrollAdminMfa();

        $first = $this->userWithMfa();
        $second = $this->userWithMfa();

        $component = Livewire::test(ListUsers::class);

        $component
            ->callTableAction('resetMfa', $first, data: [
                'mfa_code' => $this->mfaCode(),
            ])
            ->assertHasNoErrors();

        $component
            ->callTableAction('resetMfa', $second)
            ->assertHasNoErrors();

        $this->assertSame(0, $first->fresh()->mfaMethods()->count());
        $this->assertSame(0, $second->fresh()->mfaMethods()->count());
    }

    public function test_step_up_expires_after_the_freshness_window(): void
    {
        $this->enrollAdminMfa();

        $first = $this->userWithMfa();
        $second = $this->userWithMfa();

        $component = Livewire::test(ListUsers::class);

        $component
            ->callTableAction('resetMfa', $first, data: [
                'mfa_code' => $this->mfaCode(),
            ])
            ->assertHasNoErrors();

        $this->travel(6)->minutes();

        $component
            ->callTableAction('resetMfa', $second)
            ->assertHasFormErrors(['mfa_code']);

        $this->assertSame(1, $second->fresh()->mfaMethods()->count());
    }

    public function test_role_permission_changes_reject_an_invalid_authenticator_code(): void
    {
        $this->enrollAdminMfa();

        $role = Role::query()->where('name', 'hr_admin')->firstOrFail();
        $before = $role->permissions()->orderBy('name')->pluck('name')->all();

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm([
                'permissions' => ['users.view'],
                'mfa_code' => '000000',
            ])
            ->call('save')
            ->assertHasFormErrors(['mfa_code']);

        $this->assertSame(
            $before,
            $role->fresh()->permissions()->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_panel_mfa_verification_starts_the_freshness_window(): void
    {
        $this->enrollAdminMfa();

        $target = $this->userWithMfa();

        $this->assertTrue(app(RavnAppAuthentication::class)->verifyCode($this->mfaCode()));
        $this->assertTrue(app(FilamentStepUpService::class)->isFresh(auth()->user()));

        Livewire::test(ListUsers::class)
            ->callTableAction('resetMfa', $target)
            ->assertHasNoErrors();

        $this->assertSame(0, $target->fresh()->mfaMethods()->count());
    }
}
