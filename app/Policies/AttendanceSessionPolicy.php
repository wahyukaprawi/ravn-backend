<?php

namespace App\Policies;

use App\Models\AttendanceSession;
use App\Models\User;
use App\Services\ManagerResolver;

class AttendanceSessionPolicy
{
    public function __construct(
        private readonly ManagerResolver $managers,
    ) {}

    public function view(User $actor, AttendanceSession $session): bool
    {
        if ((int) $session->user_id === (int) $actor->getKey()) {
            return true;
        }

        if ($actor->hasPermissionTo('attendance.view_any')) {
            return true;
        }

        if ($actor->hasPermissionTo('attendance.view_scope')) {
            return $session->user !== null && $this->managers->isDirectReportOf($session->user, $actor);
        }

        return false;
    }
}
