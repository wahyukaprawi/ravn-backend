<?php

use App\Http\Controllers\Api\V1\Admin\AnnouncementAdminController;
use App\Http\Controllers\Api\V1\Admin\AttendanceScheduleController;
use App\Http\Controllers\Api\V1\Admin\DepartmentController;
use App\Http\Controllers\Api\V1\Admin\EmployeeImportController;
use App\Http\Controllers\Api\V1\Admin\HistoryCorrectionController;
use App\Http\Controllers\Api\V1\Admin\LeaveBalanceController as AdminLeaveBalanceController;
use App\Http\Controllers\Api\V1\Admin\LeaveCalendarController;
use App\Http\Controllers\Api\V1\Admin\LeavePolicyController;
use App\Http\Controllers\Api\V1\Admin\LeaveTypeController as AdminLeaveTypeController;
use App\Http\Controllers\Api\V1\Admin\NotificationDeliveryController;
use App\Http\Controllers\Api\V1\Admin\PositionController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\WorkLocationController;
use App\Http\Controllers\Api\V1\Announcements\AnnouncementController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceCorrectionController;
use App\Http\Controllers\Api\V1\Auth\ActivationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Employee\AssignmentController;
use App\Http\Controllers\Api\V1\Employee\EmployeeController;
use App\Http\Controllers\Api\V1\Employee\EmploymentController;
use App\Http\Controllers\Api\V1\Leave\LeaveApprovalController;
use App\Http\Controllers\Api\V1\Leave\LeaveBalanceController;
use App\Http\Controllers\Api\V1\Leave\LeaveRequestController;
use App\Http\Controllers\Api\V1\Leave\LeaveTypeController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Notifications\DeviceController;
use App\Http\Controllers\Api\V1\Notifications\NotificationController;
use App\Http\Controllers\Api\V1\Reports\ReportController;
use App\Http\Controllers\Api\V1\Reports\ReportExportController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');

    Route::prefix('auth')->group(function (): void {
        Route::post('login', LoginController::class)
            ->middleware('throttle:login')
            ->name('api.v1.auth.login');

        Route::post('activate', [ActivationController::class, 'activate'])->name('api.v1.auth.activate');

        Route::post('activation/resend', [ActivationController::class, 'resend'])
            ->middleware('throttle:activation-resend')
            ->name('api.v1.auth.activation.resend');

        Route::post('password/forgot', [PasswordResetController::class, 'forgot'])
            ->middleware('throttle:password-forgot')
            ->name('api.v1.auth.password.forgot');

        Route::post('password/reset', [PasswordResetController::class, 'reset'])
            ->middleware('throttle:password-reset')
            ->name('api.v1.auth.password.reset');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', LogoutController::class)->name('api.v1.auth.logout');

            Route::prefix('mfa')->group(function (): void {
                Route::post('enroll', [MfaController::class, 'enroll'])->name('api.v1.auth.mfa.enroll');
                Route::post('confirm', [MfaController::class, 'confirm'])->name('api.v1.auth.mfa.confirm');
                Route::post('verify', [MfaController::class, 'verify'])
                    ->middleware('session.full')
                    ->name('api.v1.auth.mfa.verify');
                Route::post('step-up', [MfaController::class, 'stepUp'])
                    ->middleware('session.full')
                    ->name('api.v1.auth.mfa.step-up');
            });
        });
    });

    Route::middleware(['auth:sanctum', 'session.full'])->group(function (): void {
        Route::get('me', MeController::class)->name('api.v1.me');

        Route::get('me/sessions', [SessionController::class, 'index'])->name('api.v1.me.sessions.index');
        Route::delete('me/sessions/{id}', [SessionController::class, 'destroy'])->name('api.v1.me.sessions.destroy');
    });

    Route::prefix('notifications')->middleware(['auth:sanctum', 'session.full'])->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])->name('api.v1.notifications.index');
        Route::post('read-all', [NotificationController::class, 'readAll'])->name('api.v1.notifications.read-all');
        Route::get('{notification}', [NotificationController::class, 'show'])->name('api.v1.notifications.show');
        Route::post('{notification}/read', [NotificationController::class, 'read'])->name('api.v1.notifications.read');
    });

    Route::prefix('devices')->middleware(['auth:sanctum', 'session.full'])->group(function (): void {
        Route::post('/', [DeviceController::class, 'store'])->name('api.v1.devices.store');
        Route::delete('{device}', [DeviceController::class, 'destroy'])->name('api.v1.devices.destroy');
    });

    Route::prefix('announcements')
        ->middleware(['auth:sanctum', 'session.full', 'permission:announcements.view'])
        ->group(function (): void {
            Route::get('/', [AnnouncementController::class, 'index'])->name('api.v1.announcements.index');
            Route::get('{announcement}', [AnnouncementController::class, 'show'])
                ->whereNumber('announcement')
                ->name('api.v1.announcements.show');
            Route::post('{announcement}/read', [AnnouncementController::class, 'read'])
                ->whereNumber('announcement')
                ->name('api.v1.announcements.read');
        });

    Route::prefix('employees')->middleware(['auth:sanctum', 'session.full'])->group(function (): void {
        Route::get('/', [EmployeeController::class, 'index'])
            ->middleware('permission:employees.view_any')
            ->name('api.v1.employees.index');
        Route::post('/', [EmployeeController::class, 'store'])
            ->middleware('permission:employees.create')
            ->name('api.v1.employees.store');
        Route::get('{employee}', [EmployeeController::class, 'show'])->name('api.v1.employees.show');
        Route::patch('{employee}', [EmployeeController::class, 'update'])
            ->middleware('permission:employees.update')
            ->name('api.v1.employees.update');
        Route::delete('{employee}', [EmployeeController::class, 'destroy'])
            ->middleware('permission:employees.delete')
            ->name('api.v1.employees.destroy');
        Route::post('{id}/restore', [EmployeeController::class, 'restore'])
            ->middleware('permission:employees.delete')
            ->name('api.v1.employees.restore');
        Route::get('{employee}/history', [EmployeeController::class, 'history'])
            ->middleware('permission:employees.view_history')
            ->name('api.v1.employees.history');

        Route::get('{employee}/employments', [EmploymentController::class, 'index'])
            ->middleware('permission:employments.view')
            ->name('api.v1.employees.employments.index');
        Route::post('{employee}/employments', [EmploymentController::class, 'store'])
            ->middleware('permission:employments.create')
            ->name('api.v1.employees.employments.store');

        Route::get('{employee}/assignments', [AssignmentController::class, 'index'])
            ->middleware('permission:assignments.view')
            ->name('api.v1.employees.assignments.index');
        Route::post('{employee}/assignments', [AssignmentController::class, 'store'])
            ->middleware('permission:assignments.create')
            ->name('api.v1.employees.assignments.store');
    });

    Route::patch('employments/{employment}', [EmploymentController::class, 'update'])
        ->middleware(['auth:sanctum', 'session.full', 'permission:employments.update'])
        ->name('api.v1.employments.update');
    Route::post('employments/{employment}/terminate', [EmploymentController::class, 'terminate'])
        ->middleware(['auth:sanctum', 'session.full', 'permission:employments.terminate'])
        ->name('api.v1.employments.terminate');

    Route::patch('assignments/{assignment}', [AssignmentController::class, 'update'])
        ->middleware(['auth:sanctum', 'session.full', 'permission:assignments.update'])
        ->name('api.v1.assignments.update');
    Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy'])
        ->middleware(['auth:sanctum', 'session.full', 'permission:assignments.update'])
        ->name('api.v1.assignments.destroy');

    Route::prefix('attendance')->middleware(['auth:sanctum', 'session.full'])->group(function (): void {
        Route::get('status', [AttendanceController::class, 'status'])
            ->name('api.v1.attendance.status');
        Route::get('history', [AttendanceController::class, 'history'])
            ->name('api.v1.attendance.history');
        Route::get('sessions/{session}', [AttendanceController::class, 'show'])
            ->name('api.v1.attendance.sessions.show');
        Route::post('challenges', [AttendanceController::class, 'challenge'])
            ->name('api.v1.attendance.challenges.store');
        Route::post('check-in', [AttendanceController::class, 'checkIn'])
            ->middleware('idempotency')
            ->name('api.v1.attendance.check-in');
        Route::post('check-out', [AttendanceController::class, 'checkOut'])
            ->middleware('idempotency')
            ->name('api.v1.attendance.check-out');

        Route::prefix('corrections')->group(function (): void {
            Route::get('/', [AttendanceCorrectionController::class, 'index'])
                ->name('api.v1.attendance.corrections.index');
            Route::post('/', [AttendanceCorrectionController::class, 'store'])
                ->middleware('permission:attendance.corrections.create')
                ->name('api.v1.attendance.corrections.store');
            Route::get('{correction}', [AttendanceCorrectionController::class, 'show'])
                ->name('api.v1.attendance.corrections.show');
            Route::post('{correction}/submit', [AttendanceCorrectionController::class, 'submit'])
                ->middleware('permission:attendance.corrections.create')
                ->name('api.v1.attendance.corrections.submit');
            Route::post('{correction}/approve', [AttendanceCorrectionController::class, 'approve'])
                ->middleware(['permission:attendance.corrections.approve', 'fresh.mfa'])
                ->name('api.v1.attendance.corrections.approve');
            Route::post('{correction}/reject', [AttendanceCorrectionController::class, 'reject'])
                ->middleware(['permission:attendance.corrections.approve', 'fresh.mfa'])
                ->name('api.v1.attendance.corrections.reject');
            Route::post('{correction}/execute', [AttendanceCorrectionController::class, 'execute'])
                ->middleware(['permission:attendance.corrections.execute', 'fresh.mfa', 'idempotency'])
                ->name('api.v1.attendance.corrections.execute');
            Route::post('{correction}/cancel', [AttendanceCorrectionController::class, 'cancel'])
                ->middleware('permission:attendance.corrections.create')
                ->name('api.v1.attendance.corrections.cancel');
            Route::get('{correction}/evidence', [AttendanceCorrectionController::class, 'evidence'])
                ->name('api.v1.attendance.corrections.evidence');
        });
    });

    Route::prefix('reports')->middleware(['auth:sanctum', 'session.full'])->group(function (): void {
        Route::get('exports', [ReportExportController::class, 'index'])
            ->middleware('permission:reports.view')
            ->name('api.v1.reports.exports.index');
        Route::get('exports/{export}', [ReportExportController::class, 'show'])
            ->middleware('permission:reports.view')
            ->name('api.v1.reports.exports.show');
        Route::get('exports/{export}/download', [ReportExportController::class, 'download'])
            ->middleware('permission:reports.view')
            ->name('api.v1.reports.exports.download');

        Route::get('/', [ReportController::class, 'index'])
            ->middleware('permission:reports.view')
            ->name('api.v1.reports.index');
        Route::get('{code}', [ReportController::class, 'show'])
            ->middleware('permission:reports.view')
            ->where('code', '[a-z0-9._-]+')
            ->name('api.v1.reports.show');
        Route::post('{code}/exports', [ReportExportController::class, 'store'])
            ->middleware('permission:reports.export')
            ->where('code', '[a-z0-9._-]+')
            ->name('api.v1.reports.exports.store');
    });

    Route::prefix('leave')->middleware(['auth:sanctum', 'session.full'])->group(function (): void {
        Route::get('types', [LeaveTypeController::class, 'index'])
            ->middleware('permission:leave_policies.view')
            ->name('api.v1.leave.types.index');

        Route::get('balances', [LeaveBalanceController::class, 'index'])
            ->name('api.v1.leave.balances.index');

        Route::get('requests', [LeaveRequestController::class, 'index'])
            ->name('api.v1.leave.requests.index');
        Route::post('requests', [LeaveRequestController::class, 'store'])
            ->middleware('idempotency')
            ->name('api.v1.leave.requests.store');
        Route::get('requests/{leaveRequest}', [LeaveRequestController::class, 'show'])
            ->name('api.v1.leave.requests.show');
        Route::post('requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])
            ->middleware('idempotency')
            ->name('api.v1.leave.requests.cancel');
        Route::post('requests/{leaveRequest}/assign-approver', [LeaveRequestController::class, 'assignApprover'])
            ->middleware(['permission:workflow.reassign', 'fresh.mfa'])
            ->name('api.v1.leave.requests.assign-approver');

        Route::get('approvals', [LeaveApprovalController::class, 'index'])
            ->middleware('permission:workflow.approve')
            ->name('api.v1.leave.approvals.index');
        Route::post('approvals/{leaveRequest}/decision', [LeaveApprovalController::class, 'decide'])
            ->middleware(['permission:workflow.approve', 'idempotency'])
            ->name('api.v1.leave.approvals.decide');
        Route::get('cancellations', [LeaveApprovalController::class, 'cancellations'])
            ->middleware('permission:workflow.approve')
            ->name('api.v1.leave.cancellations.index');
        Route::post('cancellations/{cancellation}/decision', [LeaveApprovalController::class, 'decideCancellation'])
            ->middleware(['permission:workflow.approve', 'idempotency'])
            ->name('api.v1.leave.cancellations.decide');
    });

    Route::prefix('admin')->middleware(['auth:sanctum', 'session.full'])->group(function (): void {
        Route::prefix('departments')->group(function (): void {
            Route::get('/', [DepartmentController::class, 'index'])
                ->middleware('permission:departments.view_any')
                ->name('api.v1.admin.departments.index');
            Route::post('/', [DepartmentController::class, 'store'])
                ->middleware('permission:departments.create')
                ->name('api.v1.admin.departments.store');
            Route::get('{department}', [DepartmentController::class, 'show'])
                ->middleware('permission:departments.view')
                ->name('api.v1.admin.departments.show');
            Route::patch('{department}', [DepartmentController::class, 'update'])
                ->middleware('permission:departments.update')
                ->name('api.v1.admin.departments.update');
            Route::delete('{department}', [DepartmentController::class, 'destroy'])
                ->middleware('permission:departments.delete')
                ->name('api.v1.admin.departments.destroy');
            Route::post('{id}/restore', [DepartmentController::class, 'restore'])
                ->middleware('permission:departments.delete')
                ->name('api.v1.admin.departments.restore');
        });

        Route::prefix('positions')->group(function (): void {
            Route::get('/', [PositionController::class, 'index'])
                ->middleware('permission:positions.view_any')
                ->name('api.v1.admin.positions.index');
            Route::post('/', [PositionController::class, 'store'])
                ->middleware('permission:positions.create')
                ->name('api.v1.admin.positions.store');
            Route::get('{position}', [PositionController::class, 'show'])
                ->middleware('permission:positions.view')
                ->name('api.v1.admin.positions.show');
            Route::patch('{position}', [PositionController::class, 'update'])
                ->middleware('permission:positions.update')
                ->name('api.v1.admin.positions.update');
            Route::delete('{position}', [PositionController::class, 'destroy'])
                ->middleware('permission:positions.delete')
                ->name('api.v1.admin.positions.destroy');
            Route::post('{id}/restore', [PositionController::class, 'restore'])
                ->middleware('permission:positions.delete')
                ->name('api.v1.admin.positions.restore');
        });

        Route::prefix('work-locations')->group(function (): void {
            Route::get('/', [WorkLocationController::class, 'index'])
                ->middleware('permission:work_locations.view_any')
                ->name('api.v1.admin.work-locations.index');
            Route::post('/', [WorkLocationController::class, 'store'])
                ->middleware('permission:work_locations.create')
                ->name('api.v1.admin.work-locations.store');
            Route::get('{workLocation}', [WorkLocationController::class, 'show'])
                ->middleware('permission:work_locations.view')
                ->name('api.v1.admin.work-locations.show');
            Route::patch('{workLocation}', [WorkLocationController::class, 'update'])
                ->middleware('permission:work_locations.update')
                ->name('api.v1.admin.work-locations.update');
            Route::delete('{workLocation}', [WorkLocationController::class, 'destroy'])
                ->middleware('permission:work_locations.delete')
                ->name('api.v1.admin.work-locations.destroy');
            Route::post('{id}/restore', [WorkLocationController::class, 'restore'])
                ->middleware('permission:work_locations.delete')
                ->name('api.v1.admin.work-locations.restore');
        });

        Route::prefix('attendance/schedules')->group(function (): void {
            Route::get('/', [AttendanceScheduleController::class, 'index'])
                ->middleware('permission:attendance.manage_schedules')
                ->name('api.v1.admin.attendance.schedules.index');
            Route::post('/', [AttendanceScheduleController::class, 'store'])
                ->middleware('permission:attendance.manage_schedules')
                ->name('api.v1.admin.attendance.schedules.store');
            Route::get('{schedule}', [AttendanceScheduleController::class, 'show'])
                ->middleware('permission:attendance.manage_schedules')
                ->name('api.v1.admin.attendance.schedules.show');
            Route::patch('{schedule}', [AttendanceScheduleController::class, 'update'])
                ->middleware('permission:attendance.manage_schedules')
                ->name('api.v1.admin.attendance.schedules.update');
            Route::delete('{schedule}', [AttendanceScheduleController::class, 'destroy'])
                ->middleware('permission:attendance.manage_schedules')
                ->name('api.v1.admin.attendance.schedules.destroy');
        });

        Route::post('employees/imports', [EmployeeImportController::class, 'store'])
            ->middleware('permission:employees.import')
            ->name('api.v1.admin.employees.imports.store');
        Route::get('employees/imports/{batch}', [EmployeeImportController::class, 'show'])
            ->middleware('permission:employees.import.view')
            ->name('api.v1.admin.employees.imports.show');
        Route::post('employees/imports/{batch}/commit', [EmployeeImportController::class, 'commit'])
            ->middleware(['permission:employees.import.commit', 'idempotency'])
            ->name('api.v1.admin.employees.imports.commit');

        Route::prefix('history-corrections')->group(function (): void {
            Route::get('/', [HistoryCorrectionController::class, 'index'])
                ->middleware('permission:history_corrections.view')
                ->name('api.v1.admin.history-corrections.index');
            Route::post('/', [HistoryCorrectionController::class, 'store'])
                ->middleware('permission:history_corrections.create')
                ->name('api.v1.admin.history-corrections.store');
            Route::get('{correction}', [HistoryCorrectionController::class, 'show'])
                ->middleware('permission:history_corrections.view')
                ->name('api.v1.admin.history-corrections.show');
            Route::post('{correction}/submit', [HistoryCorrectionController::class, 'submit'])
                ->middleware('permission:history_corrections.submit')
                ->name('api.v1.admin.history-corrections.submit');
            Route::post('{correction}/approve', [HistoryCorrectionController::class, 'approve'])
                ->middleware(['permission:history_corrections.approve', 'fresh.mfa'])
                ->name('api.v1.admin.history-corrections.approve');
            Route::post('{correction}/reject', [HistoryCorrectionController::class, 'reject'])
                ->middleware(['permission:history_corrections.approve', 'fresh.mfa'])
                ->name('api.v1.admin.history-corrections.reject');
            Route::post('{correction}/execute', [HistoryCorrectionController::class, 'execute'])
                ->middleware(['permission:history_corrections.execute', 'fresh.mfa'])
                ->name('api.v1.admin.history-corrections.execute');
            Route::post('{correction}/cancel', [HistoryCorrectionController::class, 'cancel'])
                ->middleware('permission:history_corrections.create')
                ->name('api.v1.admin.history-corrections.cancel');
            Route::get('{correction}/evidence', [HistoryCorrectionController::class, 'evidence'])
                ->middleware('permission:history_corrections.view')
                ->name('api.v1.admin.history-corrections.evidence');
        });

        Route::get('users', [UserController::class, 'index'])
            ->middleware('permission:users.view')
            ->name('api.v1.admin.users.index');
        Route::post('users', [UserController::class, 'store'])
            ->middleware('permission:users.create')
            ->name('api.v1.admin.users.store');
        Route::get('users/{id}', [UserController::class, 'show'])
            ->middleware('permission:users.view')
            ->name('api.v1.admin.users.show');
        Route::patch('users/{id}', [UserController::class, 'update'])
            ->middleware('permission:users.update')
            ->name('api.v1.admin.users.update');

        Route::post('users/{id}/deactivate', [UserController::class, 'deactivate'])
            ->middleware(['permission:users.deactivate', 'fresh.mfa'])
            ->name('api.v1.admin.users.deactivate');
        Route::post('users/{id}/reactivate', [UserController::class, 'reactivate'])
            ->middleware(['permission:users.activate', 'fresh.mfa'])
            ->name('api.v1.admin.users.reactivate');
        Route::post('users/{id}/revoke-sessions', [UserController::class, 'revokeSessions'])
            ->middleware('permission:users.revoke_sessions')
            ->name('api.v1.admin.users.revoke-sessions');
        Route::post('users/{id}/reset-mfa', [UserController::class, 'resetMfa'])
            ->middleware(['permission:users.reset_mfa', 'fresh.mfa'])
            ->name('api.v1.admin.users.reset-mfa');
        Route::post('users/{id}/reissue-invitation', [UserController::class, 'reissueInvitation'])
            ->middleware('permission:users.update')
            ->name('api.v1.admin.users.reissue-invitation');

        Route::put('users/{id}/roles', [UserController::class, 'assignRoles'])
            ->middleware(['permission:users.assign_roles', 'fresh.mfa'])
            ->name('api.v1.admin.users.roles.update');

        Route::get('roles', [RoleController::class, 'roles'])
            ->middleware('permission:roles.view')
            ->name('api.v1.admin.roles.index');
        Route::get('permissions', [RoleController::class, 'permissions'])
            ->middleware('permission:roles.view')
            ->name('api.v1.admin.permissions.index');

        Route::post('leave/types', [AdminLeaveTypeController::class, 'store'])
            ->middleware('permission:leave_policies.manage')
            ->name('api.v1.admin.leave.types.store');
        Route::patch('leave/types/{leaveType}', [AdminLeaveTypeController::class, 'update'])
            ->middleware('permission:leave_policies.manage')
            ->name('api.v1.admin.leave.types.update');

        Route::post('leave/policies', [LeavePolicyController::class, 'store'])
            ->middleware('permission:leave_policies.manage')
            ->name('api.v1.admin.leave.policies.store');
        Route::patch('leave/policies/{leavePolicy}', [LeavePolicyController::class, 'update'])
            ->middleware('permission:leave_policies.manage')
            ->name('api.v1.admin.leave.policies.update');

        Route::get('leave/calendars', [LeaveCalendarController::class, 'index'])
            ->middleware('permission:leave_policies.view')
            ->name('api.v1.admin.leave.calendars.index');
        Route::post('leave/calendars', [LeaveCalendarController::class, 'store'])
            ->middleware('permission:leave_calendars.manage')
            ->name('api.v1.admin.leave.calendars.store');
        Route::patch('leave/calendars/{calendar}', [LeaveCalendarController::class, 'update'])
            ->middleware('permission:leave_calendars.manage')
            ->name('api.v1.admin.leave.calendars.update');
        Route::post('leave/calendars/{calendar}/days', [LeaveCalendarController::class, 'storeDay'])
            ->middleware('permission:leave_calendars.manage')
            ->name('api.v1.admin.leave.calendars.days.store');
        Route::delete('leave/calendars/{calendar}/days/{day}', [LeaveCalendarController::class, 'destroyDay'])
            ->middleware('permission:leave_calendars.manage')
            ->name('api.v1.admin.leave.calendars.days.destroy');

        Route::post('leave/balances/adjust', [AdminLeaveBalanceController::class, 'adjust'])
            ->middleware(['permission:leave_balances.adjust', 'fresh.mfa'])
            ->name('api.v1.admin.leave.balances.adjust');

        Route::prefix('announcements')->group(function (): void {
            Route::get('/', [AnnouncementAdminController::class, 'index'])
                ->middleware('permission:announcements.manage')
                ->name('api.v1.admin.announcements.index');
            Route::post('/', [AnnouncementAdminController::class, 'store'])
                ->middleware('permission:announcements.manage')
                ->name('api.v1.admin.announcements.store');
            Route::get('{announcement}/analytics', [AnnouncementAdminController::class, 'analytics'])
                ->middleware('permission:announcements.view_analytics')
                ->whereNumber('announcement')
                ->name('api.v1.admin.announcements.analytics');
            Route::get('{announcement}', [AnnouncementAdminController::class, 'show'])
                ->middleware('permission:announcements.manage')
                ->whereNumber('announcement')
                ->name('api.v1.admin.announcements.show');
            Route::patch('{announcement}', [AnnouncementAdminController::class, 'update'])
                ->middleware('permission:announcements.manage')
                ->whereNumber('announcement')
                ->name('api.v1.admin.announcements.update');
            Route::post('{announcement}/publish', [AnnouncementAdminController::class, 'publish'])
                ->middleware('permission:announcements.publish')
                ->whereNumber('announcement')
                ->name('api.v1.admin.announcements.publish');
            Route::post('{announcement}/archive', [AnnouncementAdminController::class, 'archive'])
                ->middleware('permission:announcements.archive')
                ->whereNumber('announcement')
                ->name('api.v1.admin.announcements.archive');
        });

        Route::get('notification-deliveries', [NotificationDeliveryController::class, 'index'])
            ->middleware('permission:notifications.view_failures')
            ->name('api.v1.admin.notification-deliveries.index');
        Route::post('notification-deliveries/{delivery}/retry', [NotificationDeliveryController::class, 'retry'])
            ->middleware(['permission:notifications.retry', 'fresh.mfa'])
            ->name('api.v1.admin.notification-deliveries.retry');
    });
});
