<?php

namespace Tests\Feature;

use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Resources\AttendanceCorrections\Pages\ListAttendanceCorrections;
use App\Filament\Resources\AttendanceSessions\Pages\ListAttendanceSessions;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Departments\Pages\ListDepartments;
use App\Filament\Resources\EmployeeAssignments\Pages\ListEmployeeAssignments;
use App\Filament\Resources\EmployeeEmployments\Pages\ListEmployeeEmployments;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\LeaveBalances\Pages\ListLeaveBalances;
use App\Filament\Resources\LeavePolicies\Pages\ListLeavePolicies;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\LeaveTypes\Pages\ListLeaveTypes;
use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Filament\Resources\ReportExports\Pages\ListReportExports;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Schedules\Pages\ListSchedules;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\WorkCalendars\Pages\ListWorkCalendars;
use App\Filament\Resources\WorkLocations\Pages\ListWorkLocations;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class FilamentAdminTest extends TestCase
{
    public function test_guest_can_render_the_admin_login_page(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_panel_access_is_limited_to_admin_level_permissions(): void
    {
        $panel = Filament::getPanel('admin');

        $superAdmin = User::factory()->superAdmin()->create();
        $hrAdmin = User::factory()->hrAdmin()->create();
        $manager = User::factory()->create()->assignRole('manager');
        $employee = User::factory()->create();

        $this->assertTrue($superAdmin->canAccessPanel($panel));
        $this->assertTrue($hrAdmin->canAccessPanel($panel));
        $this->assertFalse($manager->canAccessPanel($panel));
        $this->assertFalse($employee->canAccessPanel($panel));

        $hrAdmin->forceFill(['status' => User::STATUS_INACTIVE])->save();
        $this->assertFalse($hrAdmin->fresh()->canAccessPanel($panel));
    }

    public function test_super_admin_can_render_every_admin_list_page(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $pages = [
            Dashboard::class,
            ListAnnouncements::class,
            ListAttendanceCorrections::class,
            ListAttendanceSessions::class,
            ListAuditLogs::class,
            ListDepartments::class,
            ListEmployeeAssignments::class,
            ListEmployeeEmployments::class,
            ListEmployees::class,
            ListLeaveBalances::class,
            ListLeavePolicies::class,
            ListLeaveRequests::class,
            ListLeaveTypes::class,
            ListReportExports::class,
            ListRoles::class,
            ListSchedules::class,
            ListUsers::class,
            ListWorkCalendars::class,
            ListWorkLocations::class,
        ];

        foreach ($pages as $page) {
            Livewire::test($page)->assertStatus(200);
        }
    }

    public function test_panel_access_requires_multi_factor_enrolment(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertRedirect('/admin/multi-factor-authentication/set-up');
    }

    public function test_panel_access_is_allowed_once_multi_factor_is_enrolled(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $secret = app(Google2FA::class)->generateSecretKey();
        $admin->mfaMethods()->create([
            'type' => 'totp',
            'secret' => $secret,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();
    }

    public function test_notification_delivery_page_requires_the_operational_permission(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListNotificationDeliveries::class)->assertStatus(403);

        $operator = User::factory()->create();
        $operator->givePermissionTo(['notifications.view_failures', 'notifications.retry']);

        $this->actingAs($operator);

        Livewire::test(ListNotificationDeliveries::class)->assertStatus(200);
    }
}
