<?php

namespace App\Filament\Resources\AttendanceSessions;

use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\AttendanceSessions\Pages\ListAttendanceSessions;
use App\Filament\Resources\AttendanceSessions\Pages\ViewAttendanceSession;
use App\Filament\Resources\AttendanceSessions\Schemas\AttendanceSessionInfolist;
use App\Filament\Resources\AttendanceSessions\Tables\AttendanceSessionsTable;
use App\Models\AttendanceSession;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AttendanceSessionResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = AttendanceSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|\UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Attendance sessions';

    protected static ?string $viewAnyPermission = 'attendance.view_any';

    protected static ?string $viewPermission = 'attendance.view_any';

    public static function infolist(Schema $schema): Schema
    {
        return AttendanceSessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttendanceSessionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendanceSessions::route('/'),
            'view' => ViewAttendanceSession::route('/{record}'),
        ];
    }
}
