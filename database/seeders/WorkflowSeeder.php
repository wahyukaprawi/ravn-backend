<?php

namespace Database\Seeders;

use App\Models\WorkflowDefinition;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class WorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $hrAdminRoleId = Role::findByName('hr_admin', 'web')->getKey();

        WorkflowDefinition::query()->updateOrCreate(
            ['code' => 'leave_approval'],
            [
                'name' => 'Leave approval',
                'version' => 1,
                'active' => true,
                'schema' => [
                    'steps' => [
                        'manager' => [
                            'assignee_type' => 'user',
                            'required_permissions' => ['workflow.approve'],
                        ],
                        'hr_admin' => [
                            'assignee_type' => 'role',
                            'assignee_id' => $hrAdminRoleId,
                            'required_permissions' => ['workflow.approve'],
                        ],
                    ],
                ],
            ],
        );

        WorkflowDefinition::query()->updateOrCreate(
            ['code' => 'leave_cancellation'],
            [
                'name' => 'Leave cancellation approval',
                'version' => 1,
                'active' => true,
                'schema' => [
                    'steps' => [
                        'manager' => [
                            'assignee_type' => 'user',
                            'required_permissions' => ['workflow.approve'],
                        ],
                        'hr_admin' => [
                            'assignee_type' => 'role',
                            'assignee_id' => $hrAdminRoleId,
                            'required_permissions' => ['workflow.approve'],
                        ],
                    ],
                ],
            ],
        );
    }
}
