<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmploymentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    public function create(Employee $employee, array $data, User $actor): EmployeeEmployment
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

            $from = (string) $data['effective_from'];
            $to = $data['effective_to'] ?? null;

            $this->assertRange($from, $to);

            if ($employee->employments()->count() === 0 && $from !== $employee->hire_date->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'The first employment must start on the employee hire date.',
                ]);
            }

            $this->assertNoOverlap($employee, $from, $to);

            $employment = $employee->employments()->create([
                'employment_type' => $data['employment_type'],
                'effective_from' => $from,
                'effective_to' => $to,
            ]);

            $this->audit->record('employment.created', $employment, null, $this->snapshot($employment), $actor);

            return $employment->fresh(['employee']);
        });
    }

    public function update(EmployeeEmployment $employment, array $data, User $actor): EmployeeEmployment
    {
        return DB::transaction(function () use ($employment, $data, $actor) {
            $employment = EmployeeEmployment::query()->lockForUpdate()->findOrFail($employment->getKey());

            if (! $employment->isFuture()) {
                throw ValidationException::withMessages([
                    'employment' => 'An employment that is effective or in the past must be changed through history correction.',
                ]);
            }

            $from = (string) ($data['effective_from'] ?? $employment->effective_from->toDateString());
            $to = array_key_exists('effective_to', $data) ? $data['effective_to'] : $employment->effective_to?->toDateString();

            if ($from < now()->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'A future employment cannot be moved into the past.',
                ]);
            }

            $this->assertRange($from, $to);
            $this->assertNoOverlap($employment->employee, $from, $to, $employment->getKey());
            $this->assertAssignmentsFit($employment, $from, $to);

            $before = $this->snapshot($employment);

            $employment->fill([
                'employment_type' => $data['employment_type'] ?? $employment->employment_type,
                'effective_from' => $from,
                'effective_to' => $to,
            ])->save();

            $this->audit->record('employment.updated', $employment, $before, $this->snapshot($employment), $actor);

            return $employment->fresh(['employee']);
        });
    }

    public function terminate(EmployeeEmployment $employment, string $effectiveTo, User $actor): EmployeeEmployment
    {
        return DB::transaction(function () use ($employment, $effectiveTo, $actor) {
            $employment = EmployeeEmployment::query()->lockForUpdate()->findOrFail($employment->getKey());

            if ($employment->effective_to?->toDateString() === $effectiveTo) {
                return $employment->fresh(['employee']);
            }

            if ($effectiveTo < now()->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_to' => 'A termination date cannot be in the past; use history correction.',
                ]);
            }

            if ($effectiveTo < $employment->effective_from->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_to' => 'The termination date must not precede the employment start date.',
                ]);
            }

            $this->assertAssignmentsFit($employment, $employment->effective_from->toDateString(), $effectiveTo);

            $before = $this->snapshot($employment);

            $employment->forceFill(['effective_to' => $effectiveTo])->save();

            $this->audit->record('employment.terminated', $employment, $before, $this->snapshot($employment), $actor);

            return $employment->fresh(['employee']);
        });
    }

    public function assertRange(string $from, ?string $to): void
    {
        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages([
                'effective_to' => 'The end date must be on or after the start date.',
            ]);
        }
    }

    public function validateCorrection(EmployeeEmployment $employment, array $changes): array
    {
        $from = (string) ($changes['effective_from'] ?? $employment->effective_from->toDateString());
        $to = array_key_exists('effective_to', $changes) ? $changes['effective_to'] : $employment->effective_to?->toDateString();
        $type = (string) ($changes['employment_type'] ?? $employment->employment_type);

        if (! in_array($type, EmployeeEmployment::TYPES, true)) {
            throw ValidationException::withMessages(['employment_type' => 'The employment type is invalid.']);
        }

        $this->assertRange($from, $to);
        $this->assertNoOverlap($employment->employee, $from, $to, $employment->getKey());
        $this->assertAssignmentsFit($employment, $from, $to);

        $otherEmployments = EmployeeEmployment::query()
            ->where('employee_id', $employment->employee_id)
            ->whereKeyNot($employment->getKey())
            ->exists();

        if (! $otherEmployments && $from !== $employment->employee->hire_date->toDateString()) {
            throw ValidationException::withMessages([
                'effective_from' => 'The first employment must start on the employee hire date.',
            ]);
        }

        return [
            'employment_type' => $type,
            'effective_from' => $from,
            'effective_to' => $to,
        ];
    }

    public function assertNoOverlap(Employee $employee, string $from, ?string $to, ?int $exceptId = null): void
    {
        $query = EmployeeEmployment::query()
            ->where('employee_id', $employee->getKey())
            ->where('effective_from', '<=', $to ?? '9999-12-31')
            ->where(function ($inner) use ($from) {
                $inner->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $from);
            });

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => 'Employment periods cannot overlap.',
            ]);
        }
    }

    public function assertAssignmentsFit(EmployeeEmployment $employment, string $from, ?string $to): void
    {
        $query = EmployeeAssignment::query()
            ->where('employee_employment_id', $employment->getKey())
            ->where(function ($inner) use ($from, $to) {
                $inner->where('effective_from', '<', $from);

                if ($to !== null) {
                    $inner->orWhere(function ($end) use ($to) {
                        $end->whereNull('effective_to')->orWhere('effective_to', '>', $to);
                    });
                }
            });

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'effective_to' => 'Assignments must fit within the employment period.',
            ]);
        }
    }

    protected function snapshot(EmployeeEmployment $employment): array
    {
        return [
            'employee_id' => $employment->employee_id,
            'employment_type' => $employment->employment_type,
            'effective_from' => $employment->effective_from->toDateString(),
            'effective_to' => $employment->effective_to?->toDateString(),
        ];
    }
}
