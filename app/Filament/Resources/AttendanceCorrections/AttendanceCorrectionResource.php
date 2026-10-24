<?php

namespace App\Filament\Resources\AttendanceCorrections;

use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\AttendanceCorrections\Pages\ListAttendanceCorrections;
use App\Filament\Resources\AttendanceCorrections\Pages\ViewAttendanceCorrection;
use App\Filament\Resources\AttendanceCorrections\Schemas\AttendanceCorrectionInfolist;
use App\Filament\Resources\AttendanceCorrections\Tables\AttendanceCorrectionsTable;
use App\Models\AttendanceCorrection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AttendanceCorrectionResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = AttendanceCorrection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Corrections';

    protected static ?string $viewAnyPermission = 'attendance.review';

    protected static ?string $viewPermission = 'attendance.review';

    public static function infolist(Schema $schema): Schema
    {
        return AttendanceCorrectionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttendanceCorrectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendanceCorrections::route('/'),
            'view' => ViewAttendanceCorrection::route('/{record}'),
        ];
    }
}
