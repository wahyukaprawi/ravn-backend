<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkLocation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly EmploymentService $employments,
    ) {}

    public function create(Employee $employee, array $data, User $actor): EmployeeAssignment
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

            $from = (string) $data['effective_from'];
            $to = $data['effective_to'] ?? null;

            $this->assertRange($from, $to);

            if ($from < now()->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'A new assignment cannot start in the past; use history correction.',
                ]);
            }

            $employment = EmployeeEmployment::query()
                ->whereKey($data['employee_employment_id'])
                ->where('employee_id', $employee->getKey())
                ->first();

            if ($employment === null) {
                throw ValidationException::withMessages([
                    'employee_employment_id' => 'The employment does not belong to this employee.',
                ]);
            }

            $this->assertWithinEmployment($employment, $from, $to);
            $this->assertOrganizationalContext($data);
            $this->assertManager($employee, $data['manager_id'] ?? null, $from);

            $overlapping = $this->overlappingAssignments($employee, $from, $to);
            $replaced = null;

            if ($overlapping->isNotEmpty()) {
                $candidate = $overlapping->count() === 1 ? $overlapping->first() : null;

                if ($candidate === null
                    || $candidate->effective_to !== null
                    || $candidate->effective_from->toDateString() >= $from) {
                    throw ValidationException::withMessages([
                        'effective_from' => 'Assignments cannot overlap.',
                    ]);
                }

                $replaced = $candidate;
                $replaced->forceFill([
                    'effective_to' => Carbon::parse($from)->subDay()->toDateString(),
                ])->save();
            }

            $assignment = EmployeeAssignment::query()->create([
                'employee_id' => $employee->getKey(),
                'employee_employment_id' => $employment->getKey(),
                'department_id' => $data['department_id'],
                'position_id' => $data['position_id'],
                'manager_id' => $data['manager_id'] ?? null,
                'work_location_id' => $data['work_location_id'],
                'effective_from' => $from,
                'effective_to' => $to,
                'replaced_assignment_id' => $replaced?->getKey(),
            ]);

            if ($replaced !== null) {
                $this->audit->record('assignment.replaced', $replaced, [
                    'effective_to' => null,
                ], [
                    'effective_to' => $replaced->effective_to?->toDateString(),
                    'replacement_id' => $assignment->getKey(),
                ], $actor);
            }

            $this->audit->record('assignment.created', $assignment, null, $this->snapshot($assignment), $actor);

            return $assignment->fresh(['employee', 'employment', 'department', 'position', 'manager', 'workLocation']);
        });
    }

    public function update(EmployeeAssignment $assignment, array $data, User $actor): EmployeeAssignment
    {
        return DB::transaction(function () use ($assignment, $data, $actor) {
            $assignment = EmployeeAssignment::query()->lockForUpdate()->findOrFail($assignment->getKey());
            $employee = Employee::query()->lockForUpdate()->findOrFail($assignment->employee_id);

            if ($assignment->canceled_at !== null) {
                throw ValidationException::withMessages(['assignment' => 'A cancelled assignment cannot be edited.']);
            }

            $before = $this->snapshot($assignment);

            if ($assignment->effective_from->toDateString() <= now()->toDateString()) {
                $this->updateEffectiveAssignment($assignment, $data);
            } else {
                $this->updateFutureAssignment($assignment, $employee, $data);
            }

            $this->audit->record('assignment.updated', $assignment, $before, $this->snapshot($assignment), $actor);

            return $assignment->fresh(['employee', 'employment', 'department', 'position', 'manager', 'workLocation']);
        });
    }

    public function cancel(EmployeeAssignment $assignment, User $actor): EmployeeAssignment
    {
        return DB::transaction(function () use ($assignment, $actor) {
            $assignment = EmployeeAssignment::query()->lockForUpdate()->findOrFail($assignment->getKey());

            if ($assignment->canceled_at !== null) {
                return $assignment->fresh();
            }

            if ($assignment->effective_from->toDateString() <= now()->toDateString()) {
                throw ValidationException::withMessages([
                    'assignment' => 'An assignment that has started cannot be cancelled; use history correction.',
                ]);
            }

            $before = $this->snapshot($assignment);

            $assignment->forceFill([
                'canceled_at' => now(),
                'canceled_by' => $actor->getKey(),
            ])->save();

            if ($assignment->replaced_assignment_id !== null) {
                $predecessor = EmployeeAssignment::query()->find($assignment->replaced_assignment_id);

                if ($predecessor !== null
                    && $predecessor->effective_to?->toDateString() === Carbon::parse($assignment->effective_from)->subDay()->toDateString()
                    && ! EmployeeAssignment::query()
                        ->where('employee_id', $assignment->employee_id)
                        ->whereKeyNot($assignment->getKey())
                        ->whereNull('canceled_at')
                        ->where('effective_from', '>=', $assignment->effective_from->toDateString())
                        ->exists()) {
                    $predecessor->forceFill(['effective_to' => null])->save();

                    $this->audit->record('assignment.reopened', $predecessor, [
                        'effective_to' => $predecessor->effective_to?->toDateString(),
                    ], ['effective_to' => null], $actor);
                }
            }

            $this->audit->record('assignment.cancelled', $assignment, $before, $this->snapshot($assignment), $actor);

            return $assignment->fresh(['employee', 'employment', 'department', 'position', 'manager', 'workLocation']);
        });
    }

    protected function updateFutureAssignment(EmployeeAssignment $assignment, Employee $employee, array $data): void
    {
        $from = (string) ($data['effective_from'] ?? $assignment->effective_from->toDateString());
        $to = array_key_exists('effective_to', $data) ? $data['effective_to'] : $assignment->effective_to?->toDateString();

        if ($from < now()->toDateString()) {
            throw ValidationException::withMessages([
                'effective_from' => 'A future assignment cannot be moved into the past.',
            ]);
        }

        $this->assertRange($from, $to);

        $employmentId = $data['employee_employment_id'] ?? $assignment->employee_employment_id;
        $employment = EmployeeEmployment::query()
            ->whereKey($employmentId)
            ->where('employee_id', $employee->getKey())
            ->first();

        if ($employment === null) {
            throw ValidationException::withMessages([
                'employee_employment_id' => 'The employment does not belong to this employee.',
            ]);
        }

        $merged = [
            'department_id' => $data['department_id'] ?? $assignment->department_id,
            'position_id' => $data['position_id'] ?? $assignment->position_id,
            'manager_id' => array_key_exists('manager_id', $data) ? $data['manager_id'] : $assignment->manager_id,
            'work_location_id' => $data['work_location_id'] ?? $assignment->work_location_id,
        ];

        $this->assertWithinEmployment($employment, $from, $to);
        $this->assertOrganizationalContext($merged);
        $this->assertManager($employee, $merged['manager_id'], $from);

        if ($this->overlappingAssignments($employee, $from, $to, $assignment->getKey())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'effective_from' => 'Assignments cannot overlap.',
            ]);
        }

        $assignment->fill([
            'employee_employment_id' => $employment->getKey(),
            ...$merged,
            'effective_from' => $from,
            'effective_to' => $to,
        ])->save();
    }

    protected function updateEffectiveAssignment(EmployeeAssignment $assignment, array $data): void
    {
        $unexpected = array_diff(array_keys($data), ['effective_to']);

        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'assignment' => 'An assignment that has started can only have a future end date changed; use history correction for other fields.',
            ]);
        }

        if (! array_key_exists('effective_to', $data)) {
            return;
        }

        $to = $data['effective_to'];
        $today = now()->toDateString();

        if ($to !== null && $to < $today) {
            throw ValidationException::withMessages([
                'effective_to' => 'An effective assignment cannot be ended in the past; use history correction.',
            ]);
        }

        $this->assertRange($assignment->effective_from->toDateString(), $to);

        if ($assignment->employment->effective_to !== null
            && ($to === null || $to > $assignment->employment->effective_to->toDateString())) {
            throw ValidationException::withMessages([
                'effective_to' => 'The assignment must fit within its employment period.',
            ]);
        }

        if ($this->overlappingAssignments(
            $assignment->employee,
            $assignment->effective_from->toDateString(),
            $to,
            $assignment->getKey(),
        )->isNotEmpty()) {
            throw ValidationException::withMessages([
                'effective_to' => 'The new end date would overlap another assignment.',
            ]);
        }

        $assignment->forceFill(['effective_to' => $to])->save();
    }

    protected function assertRange(string $from, ?string $to): void
    {
        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages([
                'effective_to' => 'The end date must be on or after the start date.',
            ]);
        }
    }

    protected function assertWithinEmployment(EmployeeEmployment $employment, string $from, ?string $to): void
    {
        if ($from < $employment->effective_from->toDateString()
            || ($employment->effective_to !== null && ($to === null || $to > $employment->effective_to->toDateString()))) {
            throw ValidationException::withMessages([
                'effective_from' => 'The assignment must fit within the employment period.',
            ]);
        }
    }

    protected function assertOrganizationalContext(array $data): void
    {
        $department = Department::query()->find($data['department_id']);
        $position = Position::query()->find($data['position_id']);
        $location = WorkLocation::query()->find($data['work_location_id']);

        if ($department === null || $position === null || $location === null) {
            throw ValidationException::withMessages([
                'organization' => 'Department, position and work location must exist.',
            ]);
        }

        if (! $location->active) {
            throw ValidationException::withMessages([
                'work_location_id' => 'The work location must be active.',
            ]);
        }
    }

    protected function assertManager(Employee $employee, ?int $managerId, string $from): void
    {
        if ($managerId === null) {
            return;
        }

        if ($employee->user_id !== null && (int) $employee->user_id === $managerId) {
            throw ValidationException::withMessages(['manager_id' => 'An employee cannot manage themself.']);
        }

        $manager = User::query()->whereKey($managerId)->where('status', User::STATUS_ACTIVE)->first();

        if ($manager === null) {
            throw ValidationException::withMessages(['manager_id' => 'The manager must be an active user.']);
        }

        $managerAssignment = $this->effectiveAssignmentFor($manager, $from);

        if ($managerAssignment === null) {
            throw ValidationException::withMessages([
                'manager_id' => 'The manager must have an effective assignment on the assignment start date.',
            ]);
        }

        $visited = [];
        $cursor = $manager;

        for ($depth = 0; $depth < 50; $depth++) {
            if (isset($visited[$cursor->getKey()])) {
                throw ValidationException::withMessages(['manager_id' => 'The reporting line contains a cycle.']);
            }

            $visited[$cursor->getKey()] = true;

            if ($employee->user_id !== null && (int) $employee->user_id === (int) $cursor->getKey()) {
                throw ValidationException::withMessages(['manager_id' => 'The reporting line contains a cycle.']);
            }

            $cursorAssignment = $this->effectiveAssignmentFor($cursor, $from);

            if ($cursorAssignment === null || $cursorAssignment->manager_id === null) {
                break;
            }

            $cursor = User::query()->find($cursorAssignment->manager_id);

            if ($cursor === null) {
                break;
            }
        }
    }

    protected function effectiveAssignmentFor(User $user, string $date): ?EmployeeAssignment
    {
        return EmployeeAssignment::query()
            ->whereHas('employee', fn ($query) => $query->where('user_id', $user->getKey()))
            ->whereNull('canceled_at')
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    protected function overlappingAssignments(Employee $employee, string $from, ?string $to, ?int $exceptId = null)
    {
        $query = EmployeeAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->whereNull('canceled_at')
            ->where('effective_from', '<=', $to ?? '9999-12-31')
            ->where(function ($inner) use ($from) {
                $inner->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $from);
            });

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->orderBy('effective_from')->get();
    }

    public function validateCorrection(EmployeeAssignment $assignment, array $changes): array
    {
        if ($assignment->canceled_at !== null) {
            throw ValidationException::withMessages(['assignment' => 'A cancelled assignment cannot be corrected.']);
        }

        $from = (string) ($changes['effective_from'] ?? $assignment->effective_from->toDateString());
        $to = array_key_exists('effective_to', $changes) ? $changes['effective_to'] : $assignment->effective_to?->toDateString();

        $this->assertRange($from, $to);

        $employmentId = $changes['employee_employment_id'] ?? $assignment->employee_employment_id;
        $employment = EmployeeEmployment::query()
            ->whereKey($employmentId)
            ->where('employee_id', $assignment->employee_id)
            ->first();

        if ($employment === null) {
            throw ValidationException::withMessages([
                'employee_employment_id' => 'The employment does not belong to this employee.',
            ]);
        }

        $department = Department::withTrashed()->find($changes['department_id'] ?? $assignment->department_id);
        $position = Position::withTrashed()->find($changes['position_id'] ?? $assignment->position_id);
        $location = WorkLocation::withTrashed()->find($changes['work_location_id'] ?? $assignment->work_location_id);

        if ($department === null || $position === null || $location === null) {
            throw ValidationException::withMessages([
                'organization' => 'Department, position and work location must exist.',
            ]);
        }

        $managerId = array_key_exists('manager_id', $changes) ? $changes['manager_id'] : $assignment->manager_id;

        $this->assertWithinEmployment($employment, $from, $to);
        $this->assertManager($assignment->employee, $managerId, $from);

        if ($this->overlappingAssignments($assignment->employee, $from, $to, $assignment->getKey())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'effective_from' => 'Assignments cannot overlap after the correction.',
            ]);
        }

        return [
            'employee_employment_id' => $employment->getKey(),
            'department_id' => $department->getKey(),
            'position_id' => $position->getKey(),
            'manager_id' => $managerId,
            'work_location_id' => $location->getKey(),
            'effective_from' => $from,
            'effective_to' => $to,
        ];
    }

    protected function snapshot(EmployeeAssignment $assignment): array
    {
        return [
            'employee_id' => $assignment->employee_id,
            'employee_employment_id' => $assignment->employee_employment_id,
            'department_id' => $assignment->department_id,
            'position_id' => $assignment->position_id,
            'manager_id' => $assignment->manager_id,
            'work_location_id' => $assignment->work_location_id,
            'effective_from' => $assignment->effective_from->toDateString(),
            'effective_to' => $assignment->effective_to?->toDateString(),
            'canceled_at' => $assignment->canceled_at?->toIso8601String(),
        ];
    }
}
