<?php

namespace App\Reporting;

use Illuminate\Support\Facades\DB;

class AttendanceSummaryReport implements ReportDefinition
{
    public function code(): string
    {
        return 'attendance.summary';
    }

    public function columns(): array
    {
        return [
            'employee_no' => 'Employee no',
            'name' => 'Name',
            'work_days' => 'Work days',
            'open_days' => 'Open days',
            'late_days' => 'Late days',
            'late_minutes' => 'Late minutes',
            'early_departure_days' => 'Early departure days',
            'early_departure_minutes' => 'Early departure minutes',
            'exception_days' => 'Exception days',
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

    protected function exceptionDaysExpression(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "SUM(CASE WHEN attendance_sessions.flags IS NOT NULL AND attendance_sessions.flags <> '[]' THEN 1 ELSE 0 END) as exception_days";
        }

        return 'SUM(CASE WHEN attendance_sessions.flags IS NOT NULL AND JSON_LENGTH(attendance_sessions.flags) > 0 THEN 1 ELSE 0 END) as exception_days';
    }

    public function build(array $filters, ?array $userIds): array
    {
        $query = DB::table('attendance_sessions')
            ->leftJoin('users', 'users.id', '=', 'attendance_sessions.user_id')
            ->leftJoin('employees', 'employees.user_id', '=', 'users.id')
            ->whereBetween('attendance_sessions.work_date', [$filters['from'], $filters['to']])
            ->groupBy('attendance_sessions.user_id', 'users.name', 'employees.employee_no')
            ->orderBy('users.name')
            ->orderBy('attendance_sessions.user_id')
            ->select('attendance_sessions.user_id')
            ->selectRaw('users.name as name')
            ->selectRaw('employees.employee_no as employee_no')
            ->selectRaw('COUNT(*) as work_days')
            ->selectRaw("SUM(CASE WHEN attendance_sessions.status = 'open' THEN 1 ELSE 0 END) as open_days")
            ->selectRaw('SUM(CASE WHEN attendance_sessions.late_minutes > 0 THEN 1 ELSE 0 END) as late_days')
            ->selectRaw('COALESCE(SUM(attendance_sessions.late_minutes), 0) as late_minutes')
            ->selectRaw('SUM(CASE WHEN attendance_sessions.early_departure_minutes > 0 THEN 1 ELSE 0 END) as early_departure_days')
            ->selectRaw('COALESCE(SUM(attendance_sessions.early_departure_minutes), 0) as early_departure_minutes')
            ->selectRaw($this->exceptionDaysExpression());

        if ($userIds !== null) {
            $query->whereIn('attendance_sessions.user_id', $userIds);
        }

        $rows = $query->limit((int) config('reports.max_rows') + 1)->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'employee_no' => $row->employee_no,
                'name' => $row->name,
                'work_days' => (int) $row->work_days,
                'open_days' => (int) $row->open_days,
                'late_days' => (int) $row->late_days,
                'late_minutes' => (int) $row->late_minutes,
                'early_departure_days' => (int) $row->early_departure_days,
                'early_departure_minutes' => (int) $row->early_departure_minutes,
                'exception_days' => (int) $row->exception_days,
            ])
            ->all();

        return [
            'rows' => $rows,
            'totals' => [
                'employees' => count($rows),
                'work_days' => array_sum(array_column($rows, 'work_days')),
                'late_days' => array_sum(array_column($rows, 'late_days')),
                'late_minutes' => array_sum(array_column($rows, 'late_minutes')),
                'exception_days' => array_sum(array_column($rows, 'exception_days')),
            ],
        ];
    }
}
