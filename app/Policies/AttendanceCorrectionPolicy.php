<?php

namespace App\Policies;

use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Services\ManagerResolver;

class AttendanceCorrectionPolicy
{
    public function __construct(
        private readonly ManagerResolver $managers,
    ) {}

    public function view(User $actor, AttendanceCorrection $correction): bool
    {
        if ((int) $correction->requester_id === (int) $actor->getKey()) {
            return true;
        }

        if ($actor->hasPermissionTo('attendance.view_any')) {
            return true;
        }

        if ($actor->hasPermissionTo('attendance.view_scope') && $correction->session?->user !== null) {
            return $this->managers->isDirectReportOf($correction->session->user, $actor);
        }

        return false;
    }
}
