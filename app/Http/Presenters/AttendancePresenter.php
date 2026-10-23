<?php

namespace App\Http\Presenters;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
use App\Models\Schedule;

class AttendancePresenter
{
    public static function schedule(?Schedule $schedule): ?array
    {
        if ($schedule === null) {
            return null;
        }

        return [
            'id' => $schedule->getKey(),
            'user_id' => $schedule->user_id,
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
            'location' => self::location($schedule->location),
        ];
    }

    public static function event(?AttendanceEvent $event): ?array
    {
        if ($event === null) {
            return null;
        }

        return [
            'id' => $event->getKey(),
            'event_type' => $event->event_type,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'source' => $event->source,
            'verification_status' => $event->verification_status,
            'face_verified' => $event->face_verified,
            'distance_meters' => $event->distance_meters === null ? null : (float) $event->distance_meters,
            'flags' => $event->flags,
            'corrected_at' => $event->corrected_at?->toIso8601String(),
            'correction_id' => $event->attendance_correction_id,
        ];
    }

    public static function session(?AttendanceSession $session): ?array
    {
        if ($session === null) {
            return null;
        }

        $events = $session->relationLoaded('events')
            ? $session->events->sortBy('occurred_at')->values()
            : $session->events()->orderBy('occurred_at')->get();

        return [
            'id' => $session->getKey(),
            'user' => $session->user === null ? null : [
                'id' => $session->user->getKey(),
                'name' => $session->user->name,
                'email' => $session->user->email,
            ],
            'work_date' => $session->work_date->toDateString(),
            'timezone' => $session->timezone,
            'status' => $session->status,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'scheduled_start_at' => $session->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $session->scheduled_end_at?->toIso8601String(),
            'late_minutes' => $session->late_minutes,
            'early_departure_minutes' => $session->early_departure_minutes,
            'flags' => $session->flags,
            'schedule' => self::schedule($session->schedule),
            'location' => self::location($session->workLocation),
            'events' => $events->map(fn (AttendanceEvent $event) => self::event($event))->all(),
        ];
    }

    public static function correction(?AttendanceCorrection $correction): ?array
    {
        if ($correction === null) {
            return null;
        }

        return [
            'id' => $correction->getKey(),
            'attendance_session_id' => $correction->attendance_session_id,
            'status' => $correction->status,
            'changes' => $correction->changes,
            'before' => $correction->before,
            'reason' => $correction->reason,
            'has_evidence' => $correction->evidence_path !== null,
            'requester' => self::user($correction->requester),
            'approver' => self::user($correction->approver),
            'executor' => self::user($correction->executor),
            'submitted_at' => $correction->submitted_at?->toIso8601String(),
            'decided_at' => $correction->decided_at?->toIso8601String(),
            'executed_at' => $correction->executed_at?->toIso8601String(),
            'rejected_at' => $correction->rejected_at?->toIso8601String(),
            'cancelled_at' => $correction->cancelled_at?->toIso8601String(),
            'created_at' => $correction->created_at?->toIso8601String(),
            'session' => self::session($correction->session),
        ];
    }

    public static function location($location): ?array
    {
        if ($location === null) {
            return null;
        }

        return [
            'id' => $location->getKey(),
            'code' => $location->code,
            'name' => $location->name,
            'timezone' => $location->timezone,
            'radius_meters' => $location->radius_meters,
            'active' => $location->active,
        ];
    }

    protected static function user($user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
