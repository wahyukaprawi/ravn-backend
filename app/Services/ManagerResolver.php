<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;

class ManagerResolver
{
    public function for(User $user): ?User
    {
        $employee = Employee::query()->where('user_id', $user->getKey())->first();

        if ($employee !== null) {
            $assignment = $this->assignmentFor($employee);

            if ($assignment === null || $assignment->manager_id === null) {
                return null;
            }

            return User::query()
                ->whereKey($assignment->manager_id)
                ->where('status', User::STATUS_ACTIVE)
                ->first();
        }

        $managerId = $user->manager_id;

        if ($managerId === null || (int) $managerId === (int) $user->getKey()) {
            return null;
        }

        return User::query()
            ->whereKey($managerId)
            ->where('status', User::STATUS_ACTIVE)
            ->first();
    }

    public function directReportIds(User $manager): array
    {
        $today = now()->toDateString();

        $assignmentReports = EmployeeAssignment::query()
            ->where('manager_id', $manager->getKey())
            ->whereNull('canceled_at')
            ->where('effective_from', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $today);
            })
            ->whereHas('employee', fn ($query) => $query->whereNotNull('user_id'))
            ->with('employee:id,user_id')
            ->get()
            ->pluck('employee.user_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $legacyReports = User::query()
            ->where('manager_id', $manager->getKey())
            ->whereNotIn('id', $assignmentReports)
            ->whereDoesntHave('employee')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([...$assignmentReports, ...$legacyReports]));
    }

    public function isDirectReportOf(User $employee, User $manager): bool
    {
        $profile = Employee::query()->where('user_id', $employee->getKey())->first();

        if ($profile !== null) {
            $assignment = $this->assignmentFor($profile);

            return $assignment !== null && (int) $assignment->manager_id === (int) $manager->getKey();
        }

        return (int) $employee->manager_id === (int) $manager->getKey();
    }

    public function managesEmployee(Employee $employee, User $manager): bool
    {
        $assignment = $this->assignmentFor($employee);

        return $assignment !== null && (int) $assignment->manager_id === (int) $manager->getKey();
    }

    protected function assignmentFor(Employee $employee): ?EmployeeAssignment
    {
        $today = now()->toDateString();

        return EmployeeAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->whereNull('canceled_at')
            ->where('effective_from', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $today);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
