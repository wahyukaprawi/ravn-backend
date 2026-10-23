<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    public function create(array $data, User $actor): Employee
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($data, $actor) {
            $employee = Employee::query()->create($data);

            $this->audit->record('employee.created', $employee, null, $employee->only([
                'employee_no',
                'user_id',
                'first_name',
                'last_name',
                'phone',
                'work_email',
                'hire_date',
            ]), $actor);

            return $employee;
        });
    }

    public function update(Employee $employee, array $data, User $actor): Employee
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($employee, $data, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());
            $before = $employee->only(['employee_no', 'user_id', 'first_name', 'last_name', 'phone', 'work_email', 'hire_date']);

            $employee->fill($data)->save();

            $this->audit->record(
                'employee.updated',
                $employee,
                $before,
                $employee->only(['employee_no', 'user_id', 'first_name', 'last_name', 'phone', 'work_email', 'hire_date']),
                $actor,
            );

            return $employee->fresh();
        });
    }

    public function delete(Employee $employee, User $actor): void
    {
        DB::transaction(function () use ($employee, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

            if ($employee->user !== null && $employee->user->status === User::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'employee' => 'The linked account is active. Deactivate it before deleting the employee.',
                ]);
            }

            if ($this->hasActiveOrFutureEmployment($employee) || $this->hasActiveOrFutureAssignment($employee)) {
                throw ValidationException::withMessages([
                    'employee' => 'Active or future employment/assignment history blocks deletion.',
                ]);
            }

            $before = $employee->only(['employee_no', 'user_id', 'first_name', 'last_name', 'work_email', 'hire_date']);
            $employee->delete();

            $this->audit->record('employee.deleted', $employee, $before, ['deleted_at' => $employee->deleted_at?->toIso8601String()], $actor);
        });
    }

    public function restore(int $employeeId, User $actor): Employee
    {
        return DB::transaction(function () use ($employeeId, $actor) {
            $employee = Employee::withTrashed()->lockForUpdate()->findOrFail($employeeId);

            if (! $employee->trashed()) {
                return $employee;
            }

            $conflict = Employee::withTrashed()
                ->whereKeyNot($employee->getKey())
                ->where('employee_no', $employee->employee_no)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages(['employee_no' => 'The employee number is already in use.']);
            }

            if ($employee->user_id !== null && Employee::withTrashed()
                ->whereKeyNot($employee->getKey())
                ->where('user_id', $employee->user_id)
                ->exists()) {
                throw ValidationException::withMessages(['user_id' => 'The linked user is already attached to another employee.']);
            }

            if ($employee->work_email !== null && Employee::query()
                ->whereKeyNot($employee->getKey())
                ->where('work_email', $employee->work_email)
                ->exists()) {
                throw ValidationException::withMessages(['work_email' => 'The work email is already in use by another employee.']);
            }

            $employee->restore();

            $this->audit->record('employee.restored', $employee, ['deleted_at' => $employee->getOriginal('deleted_at')], [
                'restored_at' => now()->toIso8601String(),
            ], $actor);

            return $employee->fresh();
        });
    }

    public function hasActiveOrFutureEmployment(Employee $employee): bool
    {
        $today = now()->toDateString();

        return $employee->employments()
            ->where(function ($query) use ($today) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $today);
            })
            ->exists();
    }

    public function hasActiveOrFutureAssignment(Employee $employee): bool
    {
        $today = now()->toDateString();

        return EmployeeAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->whereNull('canceled_at')
            ->where(function ($query) use ($today) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $today);
            })
            ->exists();
    }

    protected function normalize(array $data): array
    {
        if (array_key_exists('employee_no', $data)) {
            $data['employee_no'] = mb_strtoupper(trim((string) $data['employee_no']));
        }

        if (array_key_exists('work_email', $data)) {
            $data['work_email'] = $data['work_email'] === null || trim((string) $data['work_email']) === ''
                ? null
                : mb_strtolower(trim((string) $data['work_email']));
        }

        return $data;
    }
}
