<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
use App\Models\FaceVerificationChallenge;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(
        private readonly GeofenceService $geofence,
        private readonly AttendanceScheduleService $schedules,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    public function issueChallenge(User $user, array $data): FaceVerificationChallenge
    {
        return DB::transaction(function () use ($user, $data) {
            $challenge = FaceVerificationChallenge::query()->create([
                'user_id' => $user->getKey(),
                'challenge_id' => (string) str()->uuid(),
                'device_id' => $data['device_id'] ?? null,
                'status' => FaceVerificationChallenge::STATUS_PENDING,
                'attempts' => 0,
                'max_attempts' => (int) config('attendance.face_max_attempts', 3),
                'expires_at' => now()->addMinutes((int) config('attendance.face_challenge_ttl_minutes', 5)),
                'metadata' => [
                    'issued_at' => now()->toIso8601String(),
                ],
            ]);

            $this->audit->record('attendance.face_challenge.issued', $challenge, null, [
                'expires_at' => $challenge->expires_at->toIso8601String(),
            ], $user);

            return $challenge;
        });
    }

    public function checkIn(User $user, array $data, string $idempotencyKey): array
    {
        $existing = $this->eventForKey($user, AttendanceEvent::TYPE_CHECK_IN, $idempotencyKey);

        if ($existing !== null) {
            return ['replayed' => true, 'event' => $existing, 'session' => $existing->session];
        }

        $challenge = $this->assertFace($user, $data);
        $initial = $this->schedules->resolveOrFail($user);
        $this->assertLocation($data, $initial['location']);

        return DB::transaction(function () use ($user, $data, $idempotencyKey, $challenge) {
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $resolved = $this->schedules->resolveOrFail($user);
            $location = $resolved['location'];
            $schedule = $resolved['schedule'];

            $challenge = FaceVerificationChallenge::query()
                ->whereKey($challenge->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $challenge->isValid() || (int) $challenge->user_id !== (int) $user->getKey()) {
                throw ValidationException::withMessages(['face_challenge_id' => 'This face verification challenge is no longer valid.']);
            }

            $session = AttendanceSession::query()
                ->where('user_id', $user->getKey())
                ->where('work_date', $resolved['work_date'])
                ->lockForUpdate()
                ->first();

            if ($session !== null && $session->status === AttendanceSession::STATUS_CLOSED) {
                throw ValidationException::withMessages(['status' => 'This attendance session is already closed.']);
            }

            if ($session !== null && $session->events()->where('event_type', AttendanceEvent::TYPE_CHECK_IN)->exists()) {
                throw ValidationException::withMessages(['status' => 'You have already checked in for this work date.']);
            }

            if ($session === null) {
                $session = AttendanceSession::query()->create([
                    'user_id' => $user->getKey(),
                    'schedule_id' => $schedule->getKey(),
                    'work_location_id' => $location->getKey(),
                    'work_date' => $resolved['work_date'],
                    'status' => AttendanceSession::STATUS_OPEN,
                    'opened_at' => now(),
                    'timezone' => $resolved['timezone'],
                    'scheduled_start_at' => $resolved['scheduled_start_at'],
                    'scheduled_end_at' => $resolved['scheduled_end_at'],
                ]);
            }

            $distance = $this->distance($data, $location);
            $flags = $this->flags($resolved);
            $lateMinutes = $this->lateMinutes($resolved, $schedule, now());

            if ($lateMinutes > 0) {
                $flags[] = 'late';
            }

            $event = AttendanceEvent::query()->create([
                'attendance_session_id' => $session->getKey(),
                'schedule_id' => $schedule->getKey(),
                'work_location_id' => $location->getKey(),
                'user_id' => $user->getKey(),
                'event_type' => AttendanceEvent::TYPE_CHECK_IN,
                'occurred_at' => now(),
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'accuracy_meters' => $data['accuracy_meters'],
                'distance_meters' => $distance,
                'face_verified' => true,
                'verification_status' => $flags === [] ? AttendanceEvent::VERIFICATION_CLAIMED : AttendanceEvent::VERIFICATION_FLAGGED,
                'face_challenge_id' => $challenge->challenge_id,
                'device_id' => $data['device_id'] ?? null,
                'ip_address' => request()->ip(),
                'flags' => $flags === [] ? null : array_values(array_unique($flags)),
                'mock_location_detected' => false,
                'device_integrity' => $data['device_integrity'] ?? null,
                'source' => $data['source'] ?? 'app',
                'idempotency_key' => $idempotencyKey,
                'actor_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $challenge->forceFill([
                'status' => FaceVerificationChallenge::STATUS_VERIFIED,
                'verified_at' => now(),
                'used_at' => now(),
            ])->save();

            $session->forceFill([
                'flags' => array_values(array_unique([...($session->flags ?? []), ...$flags])),
                'late_minutes' => max((int) $session->late_minutes, $lateMinutes),
            ])->save();

            $this->audit->record('attendance.check_in', $session, null, [
                'event_id' => $event->getKey(),
                'work_date' => $resolved['work_date'],
                'flags' => $session->flags,
                'late_minutes' => $session->late_minutes,
            ], $user);

            $this->outbox->dispatch('attendance.check_in', [
                'attendance_session_id' => $session->getKey(),
                'event_id' => $event->getKey(),
                'user_id' => $user->getKey(),
                'work_date' => $resolved['work_date'],
                'flags' => $session->flags,
            ], 'attendance_session', $session->getKey());

            return [
                'replayed' => false,
                'event' => $event->fresh(),
                'session' => $session->fresh('events'),
            ];
        });
    }

    public function checkOut(User $user, array $data, string $idempotencyKey): array
    {
        $existing = $this->eventForKey($user, AttendanceEvent::TYPE_CHECK_OUT, $idempotencyKey);

        if ($existing !== null) {
            return ['replayed' => true, 'event' => $existing, 'session' => $existing->session];
        }

        $challenge = $this->assertFace($user, $data);
        $initial = $this->schedules->resolveOrFail($user);
        $this->assertLocation($data, $initial['location']);

        return DB::transaction(function () use ($user, $data, $idempotencyKey, $challenge) {
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $resolved = $this->schedules->resolveOrFail($user);
            $location = $resolved['location'];
            $schedule = $resolved['schedule'];

            $challenge = FaceVerificationChallenge::query()
                ->whereKey($challenge->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $challenge->isValid() || (int) $challenge->user_id !== (int) $user->getKey()) {
                throw ValidationException::withMessages(['face_challenge_id' => 'This face verification challenge is no longer valid.']);
            }

            $session = AttendanceSession::query()
                ->where('user_id', $user->getKey())
                ->where('work_date', $resolved['work_date'])
                ->lockForUpdate()
                ->first();

            if ($session === null || $session->status !== AttendanceSession::STATUS_OPEN) {
                throw ValidationException::withMessages(['status' => 'No open attendance session exists for this work date.']);
            }

            if (! $session->events()->where('event_type', AttendanceEvent::TYPE_CHECK_IN)->exists()) {
                throw ValidationException::withMessages(['status' => 'Check in before checking out.']);
            }

            if ($session->events()->where('event_type', AttendanceEvent::TYPE_CHECK_OUT)->exists()) {
                throw ValidationException::withMessages(['status' => 'You have already checked out for this work date.']);
            }

            $distance = $this->distance($data, $location);
            $flags = $this->flags($resolved);
            $earlyMinutes = $this->earlyDepartureMinutes($resolved, $schedule, now());

            if ($earlyMinutes > 0) {
                $flags[] = 'early_departure';
            }

            $event = AttendanceEvent::query()->create([
                'attendance_session_id' => $session->getKey(),
                'schedule_id' => $session->schedule_id,
                'work_location_id' => $session->work_location_id,
                'user_id' => $user->getKey(),
                'event_type' => AttendanceEvent::TYPE_CHECK_OUT,
                'occurred_at' => now(),
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'accuracy_meters' => $data['accuracy_meters'],
                'distance_meters' => $distance,
                'face_verified' => true,
                'verification_status' => $flags === [] ? AttendanceEvent::VERIFICATION_CLAIMED : AttendanceEvent::VERIFICATION_FLAGGED,
                'face_challenge_id' => $challenge->challenge_id,
                'device_id' => $data['device_id'] ?? null,
                'ip_address' => request()->ip(),
                'flags' => $flags === [] ? null : array_values(array_unique($flags)),
                'mock_location_detected' => false,
                'device_integrity' => $data['device_integrity'] ?? null,
                'source' => $data['source'] ?? 'app',
                'idempotency_key' => $idempotencyKey,
                'actor_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $challenge->forceFill([
                'status' => FaceVerificationChallenge::STATUS_VERIFIED,
                'verified_at' => now(),
                'used_at' => now(),
            ])->save();

            $session->forceFill([
                'status' => AttendanceSession::STATUS_CLOSED,
                'closed_at' => now(),
                'flags' => array_values(array_unique([...($session->flags ?? []), ...$flags])),
                'early_departure_minutes' => max((int) $session->early_departure_minutes, $earlyMinutes),
            ])->save();

            $this->audit->record('attendance.check_out', $session, null, [
                'event_id' => $event->getKey(),
                'work_date' => $resolved['work_date'],
                'flags' => $session->flags,
                'early_departure_minutes' => $session->early_departure_minutes,
            ], $user);

            $this->outbox->dispatch('attendance.check_out', [
                'attendance_session_id' => $session->getKey(),
                'event_id' => $event->getKey(),
                'user_id' => $user->getKey(),
                'work_date' => $resolved['work_date'],
                'flags' => $session->flags,
            ], 'attendance_session', $session->getKey());

            return [
                'replayed' => false,
                'event' => $event->fresh(),
                'session' => $session->fresh('events'),
            ];
        });
    }

    public function status(User $user): array
    {
        $resolved = $this->schedules->resolveFor($user);

        if ($resolved === null) {
            return [
                'schedule' => null,
                'location' => null,
                'session' => null,
                'can_check_in' => false,
                'can_check_out' => false,
                'reason' => 'schedule_missing',
            ];
        }

        $session = AttendanceSession::query()
            ->with('events')
            ->where('user_id', $user->getKey())
            ->where('work_date', $resolved['work_date'])
            ->first();

        $checkIn = $session?->checkInEvent();
        $checkOut = $session?->checkOutEvent();

        return [
            'schedule' => $resolved['schedule'],
            'location' => $resolved['location'],
            'work_date' => $resolved['work_date'],
            'timezone' => $resolved['timezone'],
            'scheduled_start_at' => $resolved['scheduled_start_at']->toIso8601String(),
            'scheduled_end_at' => $resolved['scheduled_end_at']->toIso8601String(),
            'is_workday' => $resolved['is_workday'],
            'session' => $session,
            'can_check_in' => $session === null || ($session->isOpen() && $checkIn === null),
            'can_check_out' => $session !== null && $session->isOpen() && $checkIn !== null && $checkOut === null,
        ];
    }

    protected function assertFace(User $user, array $data): FaceVerificationChallenge
    {
        $challengeId = $data['face_challenge_id'] ?? null;

        if (! is_string($challengeId) || $challengeId === '') {
            throw ValidationException::withMessages([
                'face_challenge_id' => 'A face verification challenge is required.',
            ]);
        }

        $challenge = FaceVerificationChallenge::query()
            ->where('challenge_id', $challengeId)
            ->where('user_id', $user->getKey())
            ->first();

        if ($challenge === null) {
            throw ValidationException::withMessages([
                'face_challenge_id' => 'The face verification challenge is invalid.',
            ]);
        }

        if (! $challenge->isValid()) {
            $challenge->increment('attempts');

            throw ValidationException::withMessages([
                'face_challenge_id' => 'This face verification challenge is no longer valid.',
            ]);
        }

        if (($data['face_verified'] ?? false) !== true) {
            $challenge->increment('attempts');

            if ($challenge->fresh()->attempts >= $challenge->max_attempts) {
                $challenge->forceFill([
                    'status' => FaceVerificationChallenge::STATUS_FAILED,
                    'failed_at' => now(),
                ])->save();
            }

            throw ValidationException::withMessages([
                'face_verified' => 'Face verification failed. Request a new challenge and try again.',
            ]);
        }

        if (isset($data['device_id'], $challenge->device_id)
            && $challenge->device_id !== null
            && (string) $data['device_id'] !== (string) $challenge->device_id) {
            throw ValidationException::withMessages([
                'device_id' => 'This challenge was issued to another device.',
            ]);
        }

        return $challenge;
    }

    protected function assertLocation(array $data, WorkLocation $location): void
    {
        if (($data['mock_location_detected'] ?? false) === true) {
            $this->audit->record('attendance.location.flagged', $location, null, [
                'reason' => 'mock_location_detected',
            ]);

            throw ValidationException::withMessages([
                'mock_location_detected' => 'Mock location signals are not accepted. Use the official correction process if needed.',
            ]);
        }

        foreach (['latitude', 'longitude', 'accuracy_meters'] as $field) {
            if (! isset($data[$field]) || ! is_numeric($data[$field])) {
                throw ValidationException::withMessages([$field => 'A numeric device location is required.']);
            }
        }

        $maxAccuracy = (float) config('attendance.max_accuracy_meters', 100);

        if ((float) $data['accuracy_meters'] > $maxAccuracy) {
            throw ValidationException::withMessages([
                'accuracy_meters' => 'GPS accuracy is too low. Move to an open area and try again.',
            ]);
        }

        if ($location->latitude === null || $location->longitude === null) {
            throw ValidationException::withMessages([
                'work_location' => 'The assigned work location has no coordinates.',
            ]);
        }

        $distance = $this->geofence->distanceMeters(
            (float) $location->latitude,
            (float) $location->longitude,
            (float) $data['latitude'],
            (float) $data['longitude'],
        );

        $tolerance = min(
            (float) $data['accuracy_meters'],
            (float) config('attendance.geofence_accuracy_tolerance_meters', 20),
        );

        if ($distance > (float) $location->radius_meters + $tolerance) {
            throw ValidationException::withMessages([
                'geofence' => 'You are outside the assigned work location geofence.',
            ]);
        }
    }

    protected function distance(array $data, WorkLocation $location): float
    {
        return round($this->geofence->distanceMeters(
            (float) $location->latitude,
            (float) $location->longitude,
            (float) $data['latitude'],
            (float) $data['longitude'],
        ), 2);
    }

    protected function flags(array $resolved): array
    {
        return $resolved['is_workday'] ? [] : ['outside_schedule'];
    }

    protected function lateMinutes(array $resolved, Schedule $schedule, Carbon $at): int
    {
        $start = $resolved['scheduled_start_at']->copy()->addMinutes((int) $schedule->late_grace_minutes);

        return $at->greaterThan($start) ? (int) $start->diffInMinutes($at) : 0;
    }

    protected function earlyDepartureMinutes(array $resolved, Schedule $schedule, Carbon $at): int
    {
        $end = $resolved['scheduled_end_at']->copy()->subMinutes((int) $schedule->early_departure_grace_minutes);

        return $at->lessThan($end) ? (int) $at->diffInMinutes($end) : 0;
    }

    protected function eventForKey(User $user, string $type, string $key): ?AttendanceEvent
    {
        return AttendanceEvent::query()
            ->with('session')
            ->where('user_id', $user->getKey())
            ->where('event_type', $type)
            ->where('idempotency_key', $key)
            ->first();
    }
}
