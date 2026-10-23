<?php

namespace App\Http\Presenters;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;

class EmployeePresenter
{
    public static function summary(Employee $employee): array
    {
        $assignment = $employee->relationLoaded('currentAssignment')
            ? $employee->currentAssignment
            : $employee->currentAssignment;

        return [
            'id' => $employee->getKey(),
            'employee_no' => $employee->employee_no,
            'full_name' => $employee->full_name,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'work_email' => $employee->work_email,
            'hire_date' => $employee->hire_date?->toDateString(),
            'current_assignment' => $assignment === null ? null : self::assignment($assignment),
        ];
    }

    public static function profile(Employee $employee, bool $includeHistory = false): array
    {
        $payload = [
            'id' => $employee->getKey(),
            'employee_no' => $employee->employee_no,
            'full_name' => $employee->full_name,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'phone' => $employee->phone,
            'work_email' => $employee->work_email,
            'hire_date' => $employee->hire_date?->toDateString(),
            'user' => $employee->user === null ? null : [
                'id' => $employee->user->getKey(),
                'name' => $employee->user->name,
                'email' => $employee->user->email,
                'status' => $employee->user->status,
            ],
            'current_employment' => $employee->currentEmployment === null ? null : self::employment($employee->currentEmployment),
            'current_assignment' => $employee->currentAssignment === null ? null : self::assignment($employee->currentAssignment),
            'created_at' => $employee->created_at?->toIso8601String(),
            'updated_at' => $employee->updated_at?->toIso8601String(),
            'deleted_at' => $employee->deleted_at?->toIso8601String(),
        ];

        if ($includeHistory) {
            $payload['employments'] = $employee->employments
                ->sortBy('effective_from')
                ->map(fn (EmployeeEmployment $employment) => self::employment($employment))
                ->values()
                ->all();

            $payload['assignments'] = $employee->assignments
                ->sortBy('effective_from')
                ->map(fn (EmployeeAssignment $assignment) => self::assignment($assignment))
                ->values()
                ->all();
        }

        return $payload;
    }

    public static function employment(EmployeeEmployment $employment): array
    {
        return [
            'id' => $employment->getKey(),
            'employment_type' => $employment->employment_type,
            'effective_from' => $employment->effective_from->toDateString(),
            'effective_to' => $employment->effective_to?->toDateString(),
            'status' => $employment->isEffective() ? 'active' : ($employment->isFuture() ? 'future' : 'past'),
        ];
    }

    public static function assignment(EmployeeAssignment $assignment): array
    {
        return [
            'id' => $assignment->getKey(),
            'employee_employment_id' => $assignment->employee_employment_id,
            'department' => $assignment->department === null ? null : [
                'id' => $assignment->department->getKey(),
                'code' => $assignment->department->code,
                'name' => $assignment->department->name,
            ],
            'position' => $assignment->position === null ? null : [
                'id' => $assignment->position->getKey(),
                'code' => $assignment->position->code,
                'name' => $assignment->position->name,
            ],
            'manager' => $assignment->manager === null ? null : [
                'id' => $assignment->manager->getKey(),
                'name' => $assignment->manager->name,
                'email' => $assignment->manager->email,
            ],
            'work_location' => $assignment->workLocation === null ? null : [
                'id' => $assignment->workLocation->getKey(),
                'code' => $assignment->workLocation->code,
                'name' => $assignment->workLocation->name,
                'timezone' => $assignment->workLocation->timezone,
            ],
            'effective_from' => $assignment->effective_from->toDateString(),
            'effective_to' => $assignment->effective_to?->toDateString(),
            'status' => $assignment->status(),
            'canceled_at' => $assignment->canceled_at?->toIso8601String(),
        ];
    }
}
