<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttendanceCorrectionService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly ManagerResolver $managers,
    ) {}

    public function create(User $actor, array $data, ?UploadedFile $evidence): AttendanceCorrection
    {
        $session = AttendanceSession::query()
            ->with('events')
            ->whereKey($data['attendance_session_id'])
            ->firstOrFail();

        if ((int) $session->user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('You can only request corrections for your own attendance.');
        }

        $changes = $this->validateChanges($session, $data['changes']);
        $evidencePath = null;

        if ($evidence !== null) {
            $extension = mb_strtolower($evidence->getClientOriginalExtension() ?: 'bin');
            $evidencePath = Storage::disk('local')->putFileAs(
                'attendance-corrections',
                $evidence,
                str()->uuid().'.'.$extension,
            );
        }

        return DB::transaction(function () use ($actor, $session, $changes, $data, $evidencePath) {
            $correction = AttendanceCorrection::query()->create([
                'attendance_session_id' => $session->getKey(),
                'requester_id' => $actor->getKey(),
                'status' => AttendanceCorrection::STATUS_DRAFT,
                'changes' => $changes,
                'before' => $this->snapshot($session),
                'reason' => $data['reason'],
                'evidence_path' => $evidencePath,
            ]);

            $this->audit->record('attendance.correction.created', $correction, null, [
                'attendance_session_id' => $session->getKey(),
                'changes' => $changes,
            ], $actor);

            return $correction->fresh(['session', 'requester']);
        });
    }

    public function submit(AttendanceCorrection $correction, User $actor): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction, $actor) {
            $correction = AttendanceCorrection::query()->lockForUpdate()->findOrFail($correction->getKey());

            if ($correction->status !== AttendanceCorrection::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Only a draft correction can be submitted.']);
            }

            if ((int) $correction->requester_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('Only the requester can submit this correction.');
            }

            $correction->forceFill([
                'status' => AttendanceCorrection::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ])->save();

            $this->audit->record('attendance.correction.submitted', $correction, null, ['status' => $correction->status], $actor);

            $this->outbox->dispatch('attendance.correction.submitted', [
                'correction_id' => $correction->getKey(),
                'attendance_session_id' => $correction->attendance_session_id,
                'requester_id' => $correction->requester_id,
            ], 'attendance_correction', $correction->getKey());

            return $correction->fresh(['session', 'requester', 'approver', 'executor']);
        });
    }

    public function approve(AttendanceCorrection $correction, User $actor): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction, $actor) {
            $correction = AttendanceCorrection::query()->with('session')->lockForUpdate()->findOrFail($correction->getKey());

            if ($correction->status !== AttendanceCorrection::STATUS_SUBMITTED) {
                throw ValidationException::withMessages(['status' => 'Only a submitted correction can be approved.']);
            }

            if ((int) $correction->requester_id === (int) $actor->getKey()) {
                throw new AuthorizationException('The requester cannot approve their own correction.');
            }

            if (! $this->canReview($correction->session, $actor)) {
                throw new AuthorizationException('You are not allowed to review this attendance correction.');
            }

            $correction->forceFill([
                'status' => AttendanceCorrection::STATUS_APPROVED,
                'approver_id' => $actor->getKey(),
                'decided_at' => now(),
                'approved_at' => now(),
            ])->save();

            $this->audit->record('attendance.correction.approved', $correction, null, [
                'status' => $correction->status,
                'approver_id' => $actor->getKey(),
            ], $actor);

            $this->outbox->dispatch('attendance.correction.approved', [
                'correction_id' => $correction->getKey(),
                'attendance_session_id' => $correction->attendance_session_id,
                'requester_id' => $correction->requester_id,
                'approver_id' => $actor->getKey(),
            ], 'attendance_correction', $correction->getKey());

            return $correction->fresh(['session', 'requester', 'approver', 'executor']);
        });
    }

    public function reject(AttendanceCorrection $correction, User $actor, string $reason): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction, $actor, $reason) {
            $correction = AttendanceCorrection::query()->with('session')->lockForUpdate()->findOrFail($correction->getKey());

            if ($correction->status !== AttendanceCorrection::STATUS_SUBMITTED) {
                throw ValidationException::withMessages(['status' => 'Only a submitted correction can be rejected.']);
            }

            if ((int) $correction->requester_id === (int) $actor->getKey()) {
                throw new AuthorizationException('The requester cannot reject their own correction.');
            }

            if (! $this->canReview($correction->session, $actor)) {
                throw new AuthorizationException('You are not allowed to review this attendance correction.');
            }

            $correction->forceFill([
                'status' => AttendanceCorrection::STATUS_REJECTED,
                'approver_id' => $correction->approver_id ?? $actor->getKey(),
                'decided_at' => now(),
                'rejected_at' => now(),
                'reason' => $correction->reason."\n\nRejection: ".$reason,
            ])->save();

            $this->audit->record('attendance.correction.rejected', $correction, null, [
                'status' => $correction->status,
                'reason' => $reason,
            ], $actor);

            $this->outbox->dispatch('attendance.correction.rejected', [
                'correction_id' => $correction->getKey(),
                'attendance_session_id' => $correction->attendance_session_id,
                'requester_id' => $correction->requester_id,
            ], 'attendance_correction', $correction->getKey());

            return $correction->fresh(['session', 'requester', 'approver', 'executor']);
        });
    }

    public function execute(AttendanceCorrection $correction, User $actor): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction, $actor) {
            $correction = AttendanceCorrection::query()->lockForUpdate()->findOrFail($correction->getKey());

            if ($correction->status !== AttendanceCorrection::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => 'Only an approved correction can be executed.']);
            }

            if ((int) $correction->requester_id === (int) $actor->getKey()) {
                throw new AuthorizationException('The requester cannot execute their own correction.');
            }

            if ($correction->approver_id !== null && (int) $correction->approver_id === (int) $actor->getKey()) {
                throw new AuthorizationException('The approver cannot execute the same correction.');
            }

            $session = AttendanceSession::query()->lockForUpdate()->findOrFail($correction->attendance_session_id);
            $before = $this->snapshot($session);
            $changes = $this->validateChanges($session, $correction->changes);

            if (! $this->canExecute($session, $actor)) {
                throw new AuthorizationException('You are not allowed to execute this attendance correction.');
            }

            $events = $session->events()->get()->keyBy('event_type');

            foreach (['check_in_at' => AttendanceEvent::TYPE_CHECK_IN, 'check_out_at' => AttendanceEvent::TYPE_CHECK_OUT] as $field => $type) {
                if (! array_key_exists($field, $changes)) {
                    continue;
                }

                $occurredAt = Carbon::parse($changes[$field])->utc();
                $event = $events->get($type);

                if ($event === null) {
                    AttendanceEvent::query()->create([
                        'attendance_session_id' => $session->getKey(),
                        'schedule_id' => $session->schedule_id,
                        'work_location_id' => $session->work_location_id,
                        'user_id' => $session->user_id,
                        'event_type' => $type,
                        'occurred_at' => $occurredAt,
                        'face_verified' => false,
                        'verification_status' => 'correction',
                        'flags' => ['corrected'],
                        'source' => 'correction',
                        'actor_id' => $actor->getKey(),
                        'attendance_correction_id' => $correction->getKey(),
                        'corrected_at' => now(),
                        'corrected_by' => $actor->getKey(),
                        'created_at' => now(),
                    ]);
                } else {
                    $event->forceFill([
                        'occurred_at' => $occurredAt,
                        'corrected_at' => now(),
                        'corrected_by' => $actor->getKey(),
                        'attendance_correction_id' => $correction->getKey(),
                        'verification_status' => 'correction',
                        'flags' => array_values(array_unique([...($event->flags ?? []), 'corrected'])),
                    ])->save();
                }
            }

            $checkIn = $session->events()->where('event_type', AttendanceEvent::TYPE_CHECK_IN)->orderBy('occurred_at')->first();
            $checkOut = $session->events()->where('event_type', AttendanceEvent::TYPE_CHECK_OUT)->orderBy('occurred_at')->first();

            $session->forceFill([
                'status' => $checkOut !== null ? AttendanceSession::STATUS_CLOSED : AttendanceSession::STATUS_OPEN,
                'opened_at' => $checkIn?->occurred_at ?? $session->opened_at,
                'closed_at' => $checkOut?->occurred_at,
            ])->save();

            $correction->forceFill([
                'status' => AttendanceCorrection::STATUS_EXECUTED,
                'executor_id' => $actor->getKey(),
                'executed_at' => now(),
            ])->save();

            $this->audit->record('attendance.correction.executed', $correction, $before, [
                'attendance_session_id' => $session->getKey(),
                'changes' => $changes,
                'status' => $correction->status,
            ], $actor);

            $this->outbox->dispatch('attendance.correction.executed', [
                'correction_id' => $correction->getKey(),
                'attendance_session_id' => $session->getKey(),
                'requester_id' => $correction->requester_id,
                'executor_id' => $actor->getKey(),
            ], 'attendance_correction', $correction->getKey());

            return $correction->fresh(['session', 'requester', 'approver', 'executor']);
        });
    }

    public function cancel(AttendanceCorrection $correction, User $actor): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction, $actor) {
            $correction = AttendanceCorrection::query()->lockForUpdate()->findOrFail($correction->getKey());

            if (! in_array($correction->status, [AttendanceCorrection::STATUS_DRAFT, AttendanceCorrection::STATUS_SUBMITTED], true)) {
                throw ValidationException::withMessages(['status' => 'Only a draft or submitted correction can be cancelled.']);
            }

            if ((int) $correction->requester_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('Only the requester can cancel this correction.');
            }

            $correction->forceFill([
                'status' => AttendanceCorrection::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            $this->audit->record('attendance.correction.cancelled', $correction, null, ['status' => $correction->status], $actor);

            return $correction->fresh(['session', 'requester', 'approver', 'executor']);
        });
    }

    protected function validateChanges(AttendanceSession $session, array $changes): array
    {
        $allowed = ['check_in_at', 'check_out_at'];
        $unknown = array_diff(array_keys($changes), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'changes' => 'Unsupported correction fields: '.implode(', ', $unknown).'.',
            ]);
        }

        if ($changes === []) {
            throw ValidationException::withMessages(['changes' => 'At least one change is required.']);
        }

        foreach ($changes as $field => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw ValidationException::withMessages([$field => 'The corrected time must be a valid ISO 8601 timestamp.']);
            }

            try {
                Carbon::parse($value);
            } catch (\Throwable) {
                throw ValidationException::withMessages([$field => 'The corrected time must be a valid ISO 8601 timestamp.']);
            }
        }

        if (isset($changes['check_in_at'], $changes['check_out_at'])) {
            if (Carbon::parse($changes['check_out_at'])->lessThanOrEqualTo(Carbon::parse($changes['check_in_at']))) {
                throw ValidationException::withMessages([
                    'changes' => 'The check-out time must be after the check-in time.',
                ]);
            }
        }

        $events = $session->events()->get()->keyBy('event_type');
        $checkIn = isset($changes['check_in_at'])
            ? Carbon::parse($changes['check_in_at'])
            : $events->get(AttendanceEvent::TYPE_CHECK_IN)?->occurred_at;
        $checkOut = isset($changes['check_out_at'])
            ? Carbon::parse($changes['check_out_at'])
            : $events->get(AttendanceEvent::TYPE_CHECK_OUT)?->occurred_at;

        if ($checkIn !== null && $checkOut !== null && $checkOut->lessThanOrEqualTo($checkIn)) {
            throw ValidationException::withMessages([
                'changes' => 'The check-out time must be after the check-in time.',
            ]);
        }

        return $changes;
    }

    protected function canReview(?AttendanceSession $session, User $actor): bool
    {
        if ($session === null) {
            return false;
        }

        return $actor->hasPermissionTo('attendance.view_any')
            || $this->managers->isDirectReportOf($session->user, $actor);
    }

    protected function canExecute(AttendanceSession $session, User $actor): bool
    {
        return $actor->hasPermissionTo('attendance.view_any')
            || $actor->hasRole('super_admin');
    }

    protected function snapshot(AttendanceSession $session): array
    {
        return [
            'attendance_session_id' => $session->getKey(),
            'work_date' => $session->work_date->toDateString(),
            'status' => $session->status,
            'events' => $session->events()
                ->orderBy('occurred_at')
                ->get()
                ->map(fn (AttendanceEvent $event) => [
                    'id' => $event->getKey(),
                    'event_type' => $event->event_type,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }
}
