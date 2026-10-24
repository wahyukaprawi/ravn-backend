<?php

namespace App\Filament\Widgets;

use App\Models\Announcement;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\NotificationDelivery;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationsOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Today at a glance';

    protected function getStats(): array
    {
        return [
            Stat::make('Active employees', Employee::query()->whereNull('deleted_at')->count())
                ->description('Employee profiles')
                ->descriptionIcon(Heroicon::OutlinedUsers)
                ->color('primary'),
            Stat::make('Pending leave', LeaveRequest::query()->where('status', LeaveRequest::STATUS_PENDING)->count())
                ->description('Awaiting a decision')
                ->descriptionIcon(Heroicon::OutlinedCalendarDays)
                ->color('warning'),
            Stat::make('Open attendance', AttendanceSession::query()->where('status', AttendanceSession::STATUS_OPEN)->count())
                ->description('Checked in now')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color('success'),
            Stat::make('Published announcements', Announcement::query()->where('status', Announcement::STATUS_PUBLISHED)->count())
                ->description('Visible to employees')
                ->descriptionIcon(Heroicon::OutlinedMegaphone)
                ->color('info'),
            Stat::make('Delivery attention', NotificationDelivery::query()
                ->whereIn('status', [NotificationDelivery::STATUS_FAILED, NotificationDelivery::STATUS_DEAD_LETTER])
                ->count())
                ->description('Failed or dead-lettered')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger'),
        ];
    }
}
