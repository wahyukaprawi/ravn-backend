<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function view(User $actor, LeaveRequest $leaveRequest): bool
    {
        if ((int) $leaveRequest->user_id === (int) $actor->getKey()) {
            return true;
        }

        if ($actor->hasPermissionTo('leave_requests.view_any')) {
            return true;
        }

        if ($actor->hasPermissionTo('leave_requests.view_scope')) {
            $employee = $leaveRequest->user;

            return $employee !== null && (int) $employee->manager_id === (int) $actor->getKey();
        }

        return false;
    }

    public function cancel(User $actor, LeaveRequest $leaveRequest): bool
    {
        return (int) $leaveRequest->user_id === (int) $actor->getKey() || $actor->hasRole('super_admin');
    }
}
