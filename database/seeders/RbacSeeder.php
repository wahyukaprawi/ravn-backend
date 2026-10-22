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
        'notifications.view_failures',
        'notifications.retry',
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

        foreach (self::APPROVED_PERMISSIONS as $name) {
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
            'employments.view',
            'employments.create',
            'employments.update',
            'employments.terminate',
            'assignments.view',
            'assignments.create',
            'assignments.update',
            'departments.view_any',
            'departments.view',
            'positions.view_any',
            'positions.view',
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
            'departments.view_any',
            'departments.view',
            'positions.view_any',
            'positions.view',
            'leave_requests.view_scope',
            'leave_policies.view',
            'workflow.view_own',
            'workflow.view_scope',
            'workflow.approve',
            'workflow.reject',
        ]);

        $employee = Role::findByName('employee', 'web');
        $employee->syncPermissions([
            'leave_policies.view',
            'workflow.view_own',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'RBAC seeded: %d permissions, %d roles. super_admin holds %d.',
            count(self::APPROVED_PERMISSIONS),
            count(self::ROLES),
            $superAdmin->permissions()->count(),
        ));
    }
}
