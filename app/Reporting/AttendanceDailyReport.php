<?php

namespace App\Reporting;

use App\Models\AttendanceSession;

class AttendanceDailyReport implements ReportDefinition
{
    public function code(): string
    {
        return 'attendance.daily';
    }

    public function columns(): array
    {
        return [
            'work_date' => 'Work date',
            'timezone' => 'Timezone',
            'employee_no' => 'Employee no',
            'name' => 'Name',
            'status' => 'Status',
            'scheduled_start' => 'Scheduled start',
            'scheduled_end' => 'Scheduled end',
            'check_in_at' => 'Check in',
            'check_out_at' => 'Check out',
            'late_minutes' => 'Late minutes',
            'early_departure_minutes' => 'Early departure minutes',
            'flags' => 'Flags',
        ];
    }

    public function scopes(): array
    {
        return ['self', 'team', 'all'];
    }

    public function scopePermissions(): array
    {
        return [
            'team' => 'attendance.view_scope',
            'all' => 'attendance.view_any',
        ];
    }

    public function build(array $filters, ?array $userIds): array
    {
        $query = AttendanceSession::query()
            ->with(['user.employee', 'events'])
            ->whereBetween('work_date', [$filters['from'], $filters['to']])
            ->orderBy('work_date')
            ->orderBy('user_id')
            ->orderBy('id');

        if ($userIds !== null) {
            $query->whereIn('user_id', $userIds);
        }

        $rows = [];
        $lateDays = 0;
        $lateMinutes = 0;
        $earlyDays = 0;
        $earlyMinutes = 0;

        foreach ($query->limit((int) config('reports.max_rows') + 1)->get() as $session) {
            $timezone = $session->timezone ?: (string) config('app.timezone');
            $checkIn = $session->events->sortBy('occurred_at')->firstWhere('event_type', 'check_in');
            $checkOut = $session->events->sortByDesc('occurred_at')->firstWhere('event_type', 'check_out');

            $rows[] = [
                'user_id' => (int) $session->user_id,
                'work_date' => $session->work_date->toDateString(),
                'timezone' => $timezone,
                'employee_no' => $session->user?->employee?->employee_no,
                'name' => $session->user?->name,
                'status' => $session->status,
                'scheduled_start' => $session->scheduled_start_at?->setTimezone($timezone)->format('Y-m-d H:i'),
                'scheduled_end' => $session->scheduled_end_at?->setTimezone($timezone)->format('Y-m-d H:i'),
                'check_in_at' => $checkIn?->occurred_at?->setTimezone($timezone)->format('Y-m-d H:i'),
                'check_out_at' => $checkOut?->occurred_at?->setTimezone($timezone)->format('Y-m-d H:i'),
                'late_minutes' => (int) $session->late_minutes,
                'early_departure_minutes' => (int) $session->early_departure_minutes,
                'flags' => implode(', ', $session->flags ?? []),
            ];

            $lateDays += $session->late_minutes > 0 ? 1 : 0;
            $lateMinutes += (int) $session->late_minutes;
            $earlyDays += $session->early_departure_minutes > 0 ? 1 : 0;
            $earlyMinutes += (int) $session->early_departure_minutes;
        }

        return [
            'rows' => $rows,
            'totals' => [
                'work_days' => count($rows),
                'late_days' => $lateDays,
                'late_minutes' => $lateMinutes,
                'early_departure_days' => $earlyDays,
                'early_departure_minutes' => $earlyMinutes,
            ],
        ];
    }
}
