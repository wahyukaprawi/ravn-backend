<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkCalendarDay;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class WorkCalendarService
{
    public function getCalendarForUser(User $user): ?WorkCalendar
    {
        return WorkCalendar::query()->where('active', true)->first();
    }

    public function countEligibleWorkdays(User $user, string $startDate, string $endDate): float
    {
        $calendar = $this->getCalendarForUser($user);
        $timezone = $calendar?->timezone ?? config('app.timezone');

        $start = Carbon::parse($startDate, $timezone)->startOfDay();
        $end = Carbon::parse($endDate, $timezone)->startOfDay();

        if ($end->lt($start)) {
            return 0;
        }

        $period = CarbonPeriod::create($start, $end);
        $count = 0;

        $holidays = collect();
        if ($calendar) {
            $holidays = WorkCalendarDay::query()
                ->where('work_calendar_id', $calendar->getKey())
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->where('type', 'holiday')
                ->pluck('date')
                ->map(fn ($d) => (string) $d)
                ->flip();
        }

        foreach ($period as $day) {
            if ($day->isWeekend()) {
                continue;
            }

            if ($holidays->has($day->toDateString())) {
                continue;
            }

            $count++;
        }

        return (float) $count;
    }

    public function validateTimezoneResolvable(User $user): void
    {
        $calendar = $this->getCalendarForUser($user);

        if ($calendar === null || empty($calendar->timezone)) {
            throw new \InvalidArgumentException('Employee effective work location timezone is not resolvable.');
        }
    }
}
