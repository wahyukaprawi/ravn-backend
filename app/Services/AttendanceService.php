<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
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
    ) {}

    public function checkIn(User $user, array $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $data, $idempotencyKey) {
            $existing = AttendanceEvent::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return ['status' => 'ok', 'event_id' => $existing->getKey(), 'replayed' => true];
            }

            $workDate = $this->workDateFor($user);
            $timezone = $this->resolveTimezone($user);

            $session = AttendanceSession::query()->firstOrCreate(
                ['user_id' => $user->getKey(), 'work_date' => $workDate],
                [
                    'status' => 'open',
                    'opened_at' => now(),
                    'timezone' => $timezone,
                ],
            );

            if ($session->status === 'closed') {
                throw ValidationException::withMessages(['status' => 'Attendance session is closed.']);
            }

            $this->validateLocation($user, $data);

            $event = AttendanceEvent::query()->create([
                'attendance_session_id' => $session->getKey(),
                'user_id' => $user->getKey(),
                'event_type' => 'check_in',
                'occurred_at' => now(),
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'accuracy_meters' => $data['accuracy_meters'] ?? null,
                'mock_location_detected' => $data['mock_location_detected'] ?? false,
                'device_integrity' => $data['device_integrity'] ?? null,
                'source' => $data['source'] ?? 'app',
                'idempotency_key' => $idempotencyKey,
                'actor_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            return ['status' => 'ok', 'event_id' => $event->getKey(), 'session_id' => $session->getKey()];
        });
    }

    public function checkOut(User $user, array $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $data, $idempotencyKey) {
            $existing = AttendanceEvent::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return ['status' => 'ok', 'event_id' => $existing->getKey(), 'replayed' => true];
            }

            $workDate = $this->workDateFor($user);
            $session = AttendanceSession::query()
                ->where('user_id', $user->getKey())
                ->where('work_date', $workDate)
                ->lockForUpdate()
                ->first();

            if ($session === null || $session->status === 'closed') {
                throw ValidationException::withMessages(['status' => 'No open attendance session.']);
            }

            $this->validateLocation($user, $data);

            $event = AttendanceEvent::query()->create([
                'attendance_session_id' => $session->getKey(),
                'user_id' => $user->getKey(),
                'event_type' => 'check_out',
                'occurred_at' => now(),
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'accuracy_meters' => $data['accuracy_meters'] ?? null,
                'mock_location_detected' => $data['mock_location_detected'] ?? false,
                'device_integrity' => $data['device_integrity'] ?? null,
                'source' => $data['source'] ?? 'app',
                'idempotency_key' => $idempotencyKey,
                'actor_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $session->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

            return ['status' => 'ok', 'event_id' => $event->getKey(), 'session_id' => $session->getKey()];
        });
    }

    protected function workDateFor(User $user): string
    {
        $timezone = $this->resolveTimezone($user);

        return Carbon::now($timezone)->toDateString();
    }

    protected function resolveTimezone(User $user): string
    {
        $schedule = Schedule::query()->where('user_id', $user->getKey())->where('active', true)->first();
        $location = $schedule?->work_location_id ? WorkLocation::query()->find($schedule->work_location_id) : WorkLocation::query()->where('active', true)->first();

        if ($location === null || empty($location->timezone)) {
            throw ValidationException::withMessages(['timezone' => 'Work location timezone is required.']);
        }

        return $location->timezone;
    }

    protected function validateLocation(User $user, array $data): void
    {
        if (! isset($data['latitude'], $data['longitude'])) {
            throw ValidationException::withMessages(['location' => 'Location is required for attendance.']);
        }

        $schedule = Schedule::query()->where('user_id', $user->getKey())->where('active', true)->first();
        $location = $schedule?->work_location_id ? WorkLocation::query()->find($schedule->work_location_id) : WorkLocation::query()->where('active', true)->first();

        if ($location === null) {
            throw ValidationException::withMessages(['work_location' => 'Assigned work location required.']);
        }

        $result = $this->geofence->validate($location, (float) $data['latitude'], (float) $data['longitude'], $data['accuracy_meters'] ?? null);

        if (! $result['ok']) {
            throw ValidationException::withMessages(['geofence' => 'Outside assigned work location geofence.']);
        }
    }
}
