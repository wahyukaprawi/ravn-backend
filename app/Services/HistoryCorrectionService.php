<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;
use App\Models\HistoryCorrection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class HistoryCorrectionService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly EmploymentService $employments,
        private readonly AssignmentService $assignments,
    ) {}

    public function create(array $data, ?UploadedFile $evidence, User $actor): HistoryCorrection
    {
        $entityType = (string) $data['entity_type'];
        $target = $this->findTarget($entityType, (int) $data['entity_id']);
        $changes = $data['changes'];

        if ($target->effective_from->toDateString() > now()->toDateString()) {
            throw ValidationException::withMessages([
                'entity_id' => 'A future-effective record must be changed through its normal update endpoint.',
            ]);
        }

        $allowed = $entityType === HistoryCorrection::ENTITY_EMPLOYMENT
            ? ['employment_type', 'effective_from', 'effective_to']
            : ['employee_employment_id', 'department_id', 'position_id', 'manager_id', 'work_location_id', 'effective_from', 'effective_to'];

        $unknown = array_diff(array_keys($changes), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'changes' => 'Unsupported correction fields: '.implode(', ', $unknown).'.',
            ]);
        }

        if ($changes === []) {
            throw ValidationException::withMessages(['changes' => 'At least one change is required.']);
        }

        $this->assertShape($entityType, $changes);

        $evidencePath = null;

        if ($evidence !== null) {
            $extension = mb_strtolower($evidence->getClientOriginalExtension() ?: 'bin');
            $evidencePath = Storage::disk('local')->putFileAs(
                'history-corrections',
                $evidence,
                str()->uuid().'.'.$extension,
            );
        }

        return DB::transaction(function () use ($actor, $entityType, $target, $changes, $evidencePath, $data) {
            $correction = HistoryCorrection::query()->create([
                'entity_type' => $entityType,
                'entity_id' => $target->getKey(),
                'requester_id' => $actor->getKey(),
                'status' => HistoryCorrection::STATUS_DRAFT,
                'changes' => $changes,
                'before' => $this->snapshot($target),
                'reason' => $data['reason'],
                'evidence_path' => $evidencePath,
            ]);

            $this->audit->record('history_correction.created', $correction, null, [
                'entity_type' => $entityType,
                'entity_id' => $target->getKey(),
                'changes' => $changes,
            ], $actor);

            return $correction->fresh();
        });
    }

    public function submit(HistoryCorrection $correction, User $actor): HistoryCorrection
    {
        return DB::transaction(function () use ($correction, $actor) {
            $correction = HistoryCorrection::query()->lockForUpdate()->findOrFail($correction->getKey());

            if ($correction->status !== HistoryCorrection::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Only a draft correction can be submitted.']);
            }

            if ((int) $correction->requester_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('Only the requester can submit this correction.');
            }

            $correction->forceFill([
                'status' => HistoryCorrection::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ])->save();

            $this->audit->record('history_correction.submitted', $correction, null, ['status' => $correction->status], $actor);

            return $correction->fresh();
        });
    }

    public function approve(HistoryCorrection $correction, User $actor): HistoryCorrection
    {
        return DB::transaction(function () use ($correction, $actor) {
            $correction = HistoryCorrection::query()->lockForUpdate()->findOrFail($correction->getKey());

            if ($correction->status !== HistoryCorrection::STATUS_SUBMITTED) {
                throw ValidationException::withMessages(['status' => 'Only a submitted correction can be approved.']);
            }

            if ((int) $correction->requester_id === (int) $actor->getKey()) {
                throw new AuthorizationException('The requester cannot approve their own correction.');
            }

            $correction->forceFill([
                'status' => HistoryCorrection::STATUS_APPROVED,
                'approver_id' => $actor->getKey(),
                'decided_at' => now(),
            ])->save();

            $this->audit->record('history_correction.approved', $correction, null, [
                'status' => $correction->status,
                'approver_id' => $actor->getKey(),
            ], $actor);

            return $correction->fresh();
        });
    }

    public function reject(HistoryCorrection $correction, User $actor, string $reason): HistoryCorrection
    {
        return DB::transaction(function () use ($correction, $actor, $reason) {
            $correction = HistoryCorrection::query()->lockForUpdate()->findOrFail($correction->getKey());

            if ($correction->status !== HistoryCorrection::STATUS_SUBMITTED) {
                throw ValidationException::withMessages(['status' => 'Only a submitted correction can be rejected.']);
            }

            if ((int) $correction->requester_id === (int) $actor->getKey()) {
                throw new AuthorizationException('The requester cannot reject their own correction.');
            }

            $correction->forceFill([
                'status' => HistoryCorrection::STATUS_REJECTED,
                'approver_id' => $correction->approver_id ?? $actor->getKey(),
                'decided_at' => now(),
                'rejected_at' => now(),
                'reason' => $correction->reason."\n\nRejection: ".$reason,
            ])->save();

            $this->audit->record('history_correction.rejected', $correction, null, [
                'status' => $correction->status,
                'reason' => $reason,
            ], $actor);

            return $correction->fresh();
        });
    }

    public function execute(HistoryCorrection $correction, User $actor): HistoryCorrection
    {
        return DB::transaction(function () use ($correction, $actor) {
            $correction = HistoryCorrection::query()->lockForUpdate()->findOrFail($correction->getKey());

            if ($correction->status !== HistoryCorrection::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => 'Only an approved correction can be executed.']);
            }

            if ((int) $correction->requester_id === (int) $actor->getKey()) {
                throw new AuthorizationException('The requester cannot execute their own correction.');
            }

            if ($correction->approver_id !== null && (int) $correction->approver_id === (int) $actor->getKey()) {
                throw new AuthorizationException('The approver cannot execute the same correction.');
            }

            if ($correction->entity_type === HistoryCorrection::ENTITY_EMPLOYMENT) {
                $target = EmployeeEmployment::query()->lockForUpdate()->findOrFail($correction->entity_id);
                $before = $this->snapshot($target);
                $validated = $this->employments->validateCorrection($target, $correction->changes);

                $target->forceFill($validated)->save();
            } else {
                $target = EmployeeAssignment::query()->lockForUpdate()->findOrFail($correction->entity_id);
                $before = $this->snapshot($target);
                $validated = $this->assignments->validateCorrection($target, $correction->changes);

                $target->forceFill($validated)->save();
            }

            $correction->forceFill([
                'status' => HistoryCorrection::STATUS_EXECUTED,
                'executor_id' => $actor->getKey(),
                'executed_at' => now(),
            ])->save();

            $this->audit->record('history_correction.executed', $correction, $before, [
                'entity_type' => $correction->entity_type,
                'entity_id' => $correction->entity_id,
                'before' => $before,
                'after' => $this->snapshot($target),
            ], $actor);

            return $correction->fresh();
        });
    }

    public function cancel(HistoryCorrection $correction, User $actor): HistoryCorrection
    {
        return DB::transaction(function () use ($correction, $actor) {
            $correction = HistoryCorrection::query()->lockForUpdate()->findOrFail($correction->getKey());

            if (! in_array($correction->status, [HistoryCorrection::STATUS_DRAFT, HistoryCorrection::STATUS_SUBMITTED], true)) {
                throw ValidationException::withMessages(['status' => 'Only a draft or submitted correction can be cancelled.']);
            }

            if ((int) $correction->requester_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('Only the requester can cancel this correction.');
            }

            $correction->forceFill([
                'status' => HistoryCorrection::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            $this->audit->record('history_correction.cancelled', $correction, null, ['status' => $correction->status], $actor);

            return $correction->fresh();
        });
    }

    protected function findTarget(string $entityType, int $entityId): EmployeeEmployment|EmployeeAssignment
    {
        return match ($entityType) {
            HistoryCorrection::ENTITY_EMPLOYMENT => EmployeeEmployment::query()->findOrFail($entityId),
            HistoryCorrection::ENTITY_ASSIGNMENT => EmployeeAssignment::query()->findOrFail($entityId),
            default => throw ValidationException::withMessages(['entity_type' => 'The entity type is invalid.']),
        };
    }

    protected function assertShape(string $entityType, array $changes): void
    {
        if (array_key_exists('effective_from', $changes) && ! $this->validDate((string) $changes['effective_from'])) {
            throw ValidationException::withMessages(['changes.effective_from' => 'The start date must be YYYY-MM-DD.']);
        }

        if (array_key_exists('effective_to', $changes)
            && $changes['effective_to'] !== null
            && ! $this->validDate((string) $changes['effective_to'])) {
            throw ValidationException::withMessages(['changes.effective_to' => 'The end date must be null or YYYY-MM-DD.']);
        }

        if ($entityType === HistoryCorrection::ENTITY_EMPLOYMENT) {
            if (array_key_exists('employment_type', $changes)
                && ! in_array($changes['employment_type'], EmployeeEmployment::TYPES, true)) {
                throw ValidationException::withMessages(['changes.employment_type' => 'The employment type is invalid.']);
            }

            return;
        }

        foreach (['employee_employment_id', 'department_id', 'position_id', 'work_location_id'] as $field) {
            if (array_key_exists($field, $changes) && (! is_numeric($changes[$field]) || (int) $changes[$field] < 1)) {
                throw ValidationException::withMessages(["changes.{$field}" => 'The identifier is invalid.']);
            }
        }

        if (array_key_exists('manager_id', $changes)
            && $changes['manager_id'] !== null
            && (! is_numeric($changes['manager_id']) || (int) $changes['manager_id'] < 1)) {
            throw ValidationException::withMessages(['changes.manager_id' => 'The manager identifier is invalid.']);
        }
    }

    protected function snapshot(EmployeeEmployment|EmployeeAssignment $target): array
    {
        if ($target instanceof EmployeeEmployment) {
            return [
                'employee_id' => $target->employee_id,
                'employment_type' => $target->employment_type,
                'effective_from' => $target->effective_from->toDateString(),
                'effective_to' => $target->effective_to?->toDateString(),
            ];
        }

        return [
            'employee_id' => $target->employee_id,
            'employee_employment_id' => $target->employee_employment_id,
            'department_id' => $target->department_id,
            'position_id' => $target->position_id,
            'manager_id' => $target->manager_id,
            'work_location_id' => $target->work_location_id,
            'effective_from' => $target->effective_from->toDateString(),
            'effective_to' => $target->effective_to?->toDateString(),
        ];
    }

    protected function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
