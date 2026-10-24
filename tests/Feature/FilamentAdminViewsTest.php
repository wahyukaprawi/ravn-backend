<?php

namespace Tests\Feature;

use App\Filament\Resources\Announcements\Pages\ViewAnnouncement;
use App\Filament\Resources\AttendanceCorrections\Pages\ViewAttendanceCorrection;
use App\Filament\Resources\AttendanceSessions\Pages\ViewAttendanceSession;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\LeaveRequests\Pages\ViewLeaveRequest;
use App\Filament\Resources\NotificationDeliveries\Pages\ViewNotificationDelivery;
use App\Filament\Resources\ReportExports\Pages\ViewReportExport;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\WorkLocations\Pages\ViewWorkLocation;
use App\Models\Announcement;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\WorkLocation;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentAdminViewsTest extends TestCase
{
    public function test_admin_infolists_render_for_existing_records(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::query()->where('email', 'superadmin@ravn.test')->firstOrFail();
        $employee = User::query()->where('email', 'employee@ravn.test')->firstOrFail();

        $leaveRequest = LeaveRequest::query()->create([
            'user_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->firstOrFail()->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'days' => 1,
            'status' => LeaveRequest::STATUS_PENDING,
            'reason' => 'Synthetic infolist test',
            'submitted_by' => $employee->id,
            'submitted_at' => now(),
        ]);

        $session = AttendanceSession::query()->create([
            'user_id' => $employee->id,
            'work_location_id' => WorkLocation::query()->firstOrFail()->id,
            'work_date' => now()->toDateString(),
            'status' => AttendanceSession::STATUS_CLOSED,
            'opened_at' => now()->subHours(8),
            'closed_at' => now(),
            'timezone' => 'Asia/Manila',
        ]);

        $correction = AttendanceCorrection::query()->create([
            'attendance_session_id' => $session->id,
            'requester_id' => $employee->id,
            'status' => AttendanceCorrection::STATUS_SUBMITTED,
            'changes' => ['closed_at' => now()->toDateTimeString()],
            'reason' => 'Synthetic correction',
            'submitted_at' => now(),
        ]);

        $notification = Notification::query()->create([
            'type' => 'leave.request.decided',
            'notifiable_type' => User::class,
            'notifiable_id' => $employee->id,
            'data' => ['title' => 'Synthetic notification'],
        ]);

        $delivery = NotificationDelivery::query()->create([
            'notification_id' => $notification->id,
            'channel' => NotificationDelivery::CHANNEL_IN_APP,
            'status' => NotificationDelivery::STATUS_FAILED,
            'attempts' => 1,
            'last_error' => 'Synthetic delivery failure',
        ]);

        $export = ReportExport::query()->create([
            'user_id' => $admin->id,
            'report_code' => 'attendance-summary',
            'status' => ReportExport::STATUS_FAILED,
            'format' => 'csv',
            'scope' => 'self',
            'filters' => ['from' => now()->startOfMonth()->toDateString()],
            'last_error' => 'Synthetic export failure',
        ]);

        $audit = AuditLog::query()->create([
            'actor_id' => $admin->id,
            'action' => 'synthetic.test',
            'subject_type' => User::class,
            'subject_id' => $employee->id,
            'outcome' => AuditLog::OUTCOME_SUCCESS,
            'before' => ['status' => 'pending'],
            'after' => ['status' => 'active'],
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $records = [
            [ViewAnnouncement::class, Announcement::query()->firstOrFail()],
            [ViewAttendanceCorrection::class, $correction],
            [ViewAttendanceSession::class, $session],
            [ViewAuditLog::class, $audit],
            [ViewEmployee::class, Employee::query()->firstOrFail()],
            [ViewLeaveRequest::class, $leaveRequest],
            [ViewReportExport::class, $export],
            [ViewRole::class, Role::query()->where('name', 'hr_admin')->firstOrFail()],
            [ViewUser::class, $employee],
            [ViewWorkLocation::class, WorkLocation::query()->firstOrFail()],
        ];

        foreach ($records as [$page, $record]) {
            Livewire::test($page, ['record' => $record->getRouteKey()])->assertStatus(200);
        }

        $operator = User::factory()->create();
        $operator->givePermissionTo(['notifications.view_failures', 'notifications.retry']);
        $this->actingAs($operator);

        Livewire::test(ViewNotificationDelivery::class, ['record' => $delivery->getRouteKey()])->assertStatus(200);
    }
}
