<?php

namespace App\Services;

use App\Models\User;

class ManagerResolver
{
    public function for(User $user): ?User
    {
        $managerId = $user->manager_id;

        if ($managerId === null) {
            return null;
        }

        if ((int) $managerId === (int) $user->getKey()) {
            return null;
        }

        $manager = User::query()
            ->whereKey($managerId)
            ->where('status', User::STATUS_ACTIVE)
            ->first();

        return $manager;
    }

    public function directReportIds(User $manager): array
    {
        return User::query()
            ->where('manager_id', $manager->getKey())
            ->pluck('id')
            ->all();
    }

    public function isDirectReportOf(User $employee, User $manager): bool
    {
        return (int) $employee->manager_id === (int) $manager->getKey();
    }
}
