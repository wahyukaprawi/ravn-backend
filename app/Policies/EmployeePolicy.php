<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Services\ManagerResolver;

class EmployeePolicy
{
    public function __construct(
        private readonly ManagerResolver $managers,
    ) {}

    public function view(User $actor, Employee $employee): bool
    {
        if ($employee->user_id !== null && (int) $employee->user_id === (int) $actor->getKey()) {
            return true;
        }

        if ($actor->hasPermissionTo('employees.view_all')) {
            return true;
        }

        return $actor->hasPermissionTo('employees.view')
            && $this->managers->managesEmployee($employee, $actor);
    }

    public function update(User $actor, Employee $employee): bool
    {
        return $actor->hasPermissionTo('employees.update')
            && $actor->hasPermissionTo('employees.view_all');
    }

    public function delete(User $actor, Employee $employee): bool
    {
        return $actor->hasPermissionTo('employees.delete')
            && $actor->hasPermissionTo('employees.view_all');
    }

    public function restore(User $actor, Employee $employee): bool
    {
        return $this->delete($actor, $employee);
    }

    public function viewHistory(User $actor, Employee $employee): bool
    {
        return $actor->hasPermissionTo('employees.view_history') && $this->view($actor, $employee);
    }
}
