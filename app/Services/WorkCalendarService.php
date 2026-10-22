<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkCalendarDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use InvalidArgumentException;

class WorkCalendarService
{
    public function getCalendarForUser(User $user): ?WorkCalendar
    {
        return WorkCalendar::query()->where('active', true)->first();
    }

    public function countEligibleWorkdays(User $user, string $startDate, string $endDate): float
    {
        $timezone = $this->timezoneFor($user);

        $start = Carbon::parse($startDate, $timezone)->startOfDay();
        $end = Carbon::parse($endDate, $timezone)->startOfDay();

        if ($end->lt($start)) {
            return 0;
        }

        $holidays = $this->holidaysBetween($user, $start, $end);
        $count = 0;

        foreach (CarbonPeriod::create($start, $end) as $day) {
            if ($this->isEligibleDay($day, $holidays)) {
                $count++;
            }
        }

        return (float) $count;
    }

    public function addWorkdays(User $user, CarbonInterface $from, int $days): Carbon
    {
        $cursor = Carbon::instance($from)->copy();
        $added = 0;

        while ($added < $days) {
            $cursor = $cursor->copy()->addDay();

            if ($this->isEligibleDay($cursor, $this->holidaysBetween($user, $cursor, $cursor))) {
                $added++;
            }
        }

        return $cursor;
    }

    public function timezoneFor(User $user): string
    {
        $calendar = $this->getCalendarForUser($user);

        if ($calendar === null || empty($calendar->timezone)) {
            throw new InvalidArgumentException('Employee effective work location timezone is not resolvable.');
        }

        return $calendar->timezone;
    }

    public function todayFor(User $user): Carbon
    {
        return Carbon::now($this->timezoneFor($user))->startOfDay();
    }

    public function validateTimezoneResolvable(User $user): void
    {
        $this->timezoneFor($user);
    }

    protected function isEligibleDay(CarbonInterface $day, array $holidays): bool
    {
        if ($day->isWeekend()) {
            return false;
        }

        return ! in_array($day->toDateString(), $holidays, true);
    }

    protected function holidaysBetween(User $user, CarbonInterface $start, CarbonInterface $end): array
    {
        $calendar = $this->getCalendarForUser($user);

        if ($calendar === null) {
            return [];
        }

        return WorkCalendarDay::query()
            ->where('work_calendar_id', $calendar->getKey())
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('type', 'holiday')
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->all();
    }
}
