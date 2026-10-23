<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RbacSeeder extends Seeder
{
    public const APPROVED_PERMISSIONS = [
        'employees.view_any',
        'employees.view',
        'employees.view_all',
        'employees.create',
        'employees.update',
        'employees.delete',
        'employments.view',
        'employments.create',
        'employments.update',
        'employments.terminate',
        'assignments.view',
        'assignments.create',
        'assignments.update',
        'departments.view_any',
        'departments.view',
        'departments.create',
        'departments.update',
        'departments.delete',
        'positions.view_any',
        'positions.view',
        'positions.create',
        'positions.update',
        'positions.delete',
        'users.view_access_status',
        'users.activate',
        'users.deactivate',
        'audit.view',
        'history_corrections.view',
        'history_corrections.create',
        'history_corrections.submit',
        'history_corrections.approve',
        'history_corrections.execute',
        'users.create',
        'users.view',
        'users.update',
        'users.revoke_sessions',
        'users.assign_roles',
        'roles.view',
        'roles.manage',
        'leave_requests.view_any',
        'leave_requests.view_scope',
        'leave_policies.view',
        'leave_policies.manage',
        'leave_calendars.manage',
        'leave_balances.adjust',
        'workflow.view_own',
        'workflow.view_scope',
        'workflow.approve',
        'workflow.reject',
        'workflow.reassign',
        'workflow.escalations.view',
        'workflow.manage',
        'employees.view_history',
        'employees.import',
        'employees.import.view',
        'employees.import.commit',
        'work_locations.view_any',
        'work_locations.view',
        'work_locations.create',
        'work_locations.update',
        'work_locations.delete',
        'attendance.view_any',
        'attendance.view_scope',
        'attendance.review',
        'attendance.manage_schedules',
        'attendance.reports.view',
        'attendance.corrections.create',
        'attendance.corrections.approve',
        'attendance.corrections.execute',
        'reports.view',
        'reports.export',
        'reports.view_sensitive',
        'reports.manage_exports',
    ];

    public const OPERATIONAL_PERMISSIONS = [
        'notifications.view_failures',
        'notifications.retry',
    ];

    public const SLICE_01_PERMISSIONS = [
        'users.create',
        'users.view',
        'users.update',
        'users.revoke_sessions',
        'users.assign_roles',
        'roles.view',
        'roles.manage',
    ];

    public const ROLES = [
        'super_admin',
        'hr_admin',
        'manager',
        'employee',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_merge(self::APPROVED_PERMISSIONS, self::OPERATIONAL_PERMISSIONS) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (self::ROLES as $name) {
            Role::findOrCreate($name, 'web');
        }

        $superAdmin = Role::findByName('super_admin', 'web');
        $superAdmin->syncPermissions(self::APPROVED_PERMISSIONS);

        $hrAdmin = Role::findByName('hr_admin', 'web');
        $hrAdmin->syncPermissions([
            'users.create',
            'users.view',
            'users.update',
            'users.revoke_sessions',
            'users.assign_roles',
            'roles.view',
            'employees.view_any',
            'employees.view',
            'employees.view_all',
            'employees.create',
            'employees.update',
            'employees.delete',
            'employees.view_history',
            'employees.import',
            'employees.import.view',
            'employees.import.commit',
            'employments.view',
            'employments.create',
            'employments.update',
            'employments.terminate',
            'assignments.view',
            'assignments.create',
            'assignments.update',
            'departments.view_any',
            'departments.view',
            'departments.create',
            'departments.update',
            'departments.delete',
            'positions.view_any',
            'positions.view',
            'positions.create',
            'positions.update',
            'positions.delete',
            'work_locations.view_any',
            'work_locations.view',
            'work_locations.create',
            'work_locations.update',
            'work_locations.delete',
            'attendance.view_any',
            'attendance.review',
            'attendance.manage_schedules',
            'attendance.reports.view',
            'attendance.corrections.create',
            'attendance.corrections.approve',
            'attendance.corrections.execute',
            'reports.view',
            'reports.export',
            'reports.manage_exports',
            'history_corrections.view',
            'history_corrections.create',
            'history_corrections.submit',
            'history_corrections.approve',
            'history_corrections.execute',
            'audit.view',
            'leave_requests.view_any',
            'leave_policies.view',
            'leave_policies.manage',
            'leave_calendars.manage',
            'leave_balances.adjust',
            'workflow.view_own',
            'workflow.view_scope',
            'workflow.approve',
            'workflow.reject',
            'workflow.reassign',
            'workflow.escalations.view',
        ]);

        $manager = Role::findByName('manager', 'web');
        $manager->syncPermissions([
            'employees.view_any',
            'employees.view',
            'employees.view_history',
            'departments.view_any',
            'departments.view',
            'positions.view_any',
            'positions.view',
            'work_locations.view_any',
            'work_locations.view',
            'attendance.view_scope',
            'attendance.review',
            'attendance.reports.view',
            'attendance.corrections.create',
            'attendance.corrections.approve',
            'reports.view',
            'reports.export',
            'leave_requests.view_scope',
            'leave_policies.view',
            'workflow.view_own',
            'workflow.view_scope',
            'workflow.approve',
            'workflow.reject',
        ]);

        $employee = Role::findByName('employee', 'web');
        $employee->syncPermissions([
            'employees.view',
            'attendance.corrections.create',
            'leave_policies.view',
            'workflow.view_own',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'RBAC seeded: %d permissions, %d roles. super_admin holds %d.',
            count(self::APPROVED_PERMISSIONS) + count(self::OPERATIONAL_PERMISSIONS),
            count(self::ROLES),
            $superAdmin->permissions()->count(),
        ));
    }
}
