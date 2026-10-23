<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceScheduleService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    public function create(array $data, User $actor): Schedule
    {
        $this->validate($data);

        return DB::transaction(function () use ($data, $actor) {
            $schedule = Schedule::query()->create($data);

            $this->audit->record('attendance.schedule.created', $schedule, null, $this->snapshot($schedule), $actor);

            $this->outbox->dispatch('attendance.schedule.created', [
                'schedule_id' => $schedule->getKey(),
                'user_id' => $schedule->user_id,
                'work_location_id' => $schedule->work_location_id,
            ], 'schedule', $schedule->getKey());

            return $schedule->fresh('location');
        });
    }

    public function update(Schedule $schedule, array $data, User $actor): Schedule
    {
        $merged = array_merge($this->snapshot($schedule), $data);

        $this->validate($merged, $schedule);

        return DB::transaction(function () use ($schedule, $data, $actor) {
            $schedule = Schedule::query()->lockForUpdate()->findOrFail($schedule->getKey());
            $before = $this->snapshot($schedule);

            $schedule->fill($data)->save();

            $this->audit->record('attendance.schedule.updated', $schedule, $before, $this->snapshot($schedule), $actor);

            $this->outbox->dispatch('attendance.schedule.updated', [
                'schedule_id' => $schedule->getKey(),
                'user_id' => $schedule->user_id,
                'work_location_id' => $schedule->work_location_id,
            ], 'schedule', $schedule->getKey());

            return $schedule->fresh('location');
        });
    }

    public function delete(Schedule $schedule, User $actor): void
    {
        DB::transaction(function () use ($schedule, $actor) {
            $schedule = Schedule::query()->lockForUpdate()->findOrFail($schedule->getKey());
            $before = $this->snapshot($schedule);

            $schedule->delete();

            $this->audit->record('attendance.schedule.deleted', $schedule, $before, ['deleted_at' => now()->toIso8601String()], $actor);

            $this->outbox->dispatch('attendance.schedule.deleted', [
                'schedule_id' => $schedule->getKey(),
                'user_id' => $schedule->user_id,
            ], 'schedule', $schedule->getKey());
        });
    }

    public function resolveFor(User $user, ?Carbon $at = null): ?array
    {
        $employee = Employee::query()
            ->where('user_id', $user->getKey())
            ->first();

        $assignment = $this->currentAssignment($employee);

        $location = $assignment?->workLocation;

        if ($location === null) {
            return null;
        }

        $timezone = (string) $location->timezone;
        $local = ($at ?? now())->copy()->setTimezone($timezone);
        $localDate = $local->toDateString();
        $previousDate = $local->copy()->subDay()->toDateString();

        $schedule = $this->effectiveSchedule($user, $localDate);

        if ($schedule !== null && $schedule->overnight) {
            $previous = $this->effectiveSchedule($user, $previousDate);

            if ($previous !== null && $previous->overnight) {
                [$previousStart, $previousEnd] = $previous->shiftBounds($previousDate, $timezone);

                if ($local->lessThan($previousEnd)) {
                    $schedule = $previous;
                    $localDate = $previousDate;
                }
            }
        } elseif ($schedule === null) {
            $previous = $this->effectiveSchedule($user, $previousDate);

            if ($previous !== null && $previous->overnight) {
                [$previousStart, $previousEnd] = $previous->shiftBounds($previousDate, $timezone);

                if ($local->lessThan($previousEnd)) {
                    $schedule = $previous;
                    $localDate = $previousDate;
                }
            }
        }

        if ($schedule === null) {
            return null;
        }

        $scheduleLocation = $schedule->location;

        if ($scheduleLocation !== null) {
            $location = $scheduleLocation;
            $timezone = (string) $location->timezone;
        }

        [$scheduledStart, $scheduledEnd] = $schedule->shiftBounds($localDate, $timezone);

        return [
            'schedule' => $schedule,
            'location' => $location,
            'work_date' => $localDate,
            'timezone' => $timezone,
            'scheduled_start_at' => $scheduledStart->copy()->utc(),
            'scheduled_end_at' => $scheduledEnd->copy()->utc(),
            'is_workday' => $schedule->isWorkdayFor($localDate),
        ];
    }

    public function resolveOrFail(User $user, ?Carbon $at = null): array
    {
        $resolved = $this->resolveFor($user, $at);

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'schedule' => 'A valid effective work schedule is required for attendance.',
            ]);
        }

        return $resolved;
    }

    public function forSession(AttendanceSession $session): ?array
    {
        if ($session->schedule === null || $session->workLocation === null) {
            return null;
        }

        return [
            'schedule' => $session->schedule,
            'location' => $session->workLocation,
            'work_date' => $session->work_date->toDateString(),
            'timezone' => $session->timezone,
            'scheduled_start_at' => $session->scheduled_start_at,
            'scheduled_end_at' => $session->scheduled_end_at,
            'is_workday' => $session->schedule->isWorkdayFor($session->work_date->toDateString()),
        ];
    }

    protected function currentAssignment(?Employee $employee): ?EmployeeAssignment
    {
        if ($employee === null) {
            return null;
        }

        $today = now()->toDateString();

        return EmployeeAssignment::query()
            ->with('workLocation')
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

    protected function effectiveSchedule(User $user, string $date): ?Schedule
    {
        return Schedule::query()
            ->with('location')
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    protected function validate(array $data, ?Schedule $ignore = null): void
    {
        $userId = (int) $data['user_id'];
        $locationId = (int) $data['work_location_id'];

        if (! User::query()->whereKey($userId)->where('status', User::STATUS_ACTIVE)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'The employee must be an active user.']);
        }

        if (! WorkLocation::query()->whereKey($locationId)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['work_location_id' => 'The work location must be active.']);
        }

        $from = (string) $data['effective_from'];
        $to = $data['effective_to'] ?? null;

        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => 'The end date must be on or after the start date.']);
        }

        $days = array_values(array_unique(array_map('intval', $data['days'] ?? [])));

        if ($days === [] || array_diff($days, array_keys(Schedule::DAYS)) !== []) {
            throw ValidationException::withMessages(['days' => 'Choose at least one valid weekday.']);
        }

        if (($data['start_time'] ?? null) === ($data['end_time'] ?? null)) {
            throw ValidationException::withMessages(['end_time' => 'The end time must differ from the start time.']);
        }

        $overlap = Schedule::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->where('effective_from', '<=', $to ?? '9999-12-31')
            ->where(function ($query) use ($from) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $from);
            })
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['effective_from' => 'This schedule overlaps another effective schedule for the user.']);
        }
    }

    protected function snapshot(Schedule $schedule): array
    {
        return [
            'user_id' => $schedule->user_id,
            'work_location_id' => $schedule->work_location_id,
            'name' => $schedule->name,
            'effective_from' => $schedule->effective_from?->toDateString(),
            'effective_to' => $schedule->effective_to?->toDateString(),
            'days' => $schedule->days,
            'start_time' => $schedule->start_time,
            'end_time' => $schedule->end_time,
            'overnight' => $schedule->overnight,
            'late_grace_minutes' => $schedule->late_grace_minutes,
            'early_departure_grace_minutes' => $schedule->early_departure_grace_minutes,
            'active' => $schedule->active,
        ];
    }
}
