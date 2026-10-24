<?php

namespace App\Filament\Resources\WorkCalendars;

use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\WorkCalendars\Pages\CreateWorkCalendar;
use App\Filament\Resources\WorkCalendars\Pages\EditWorkCalendar;
use App\Filament\Resources\WorkCalendars\Pages\ListWorkCalendars;
use App\Filament\Resources\WorkCalendars\Schemas\WorkCalendarForm;
use App\Filament\Resources\WorkCalendars\Tables\WorkCalendarsTable;
use App\Models\WorkCalendar;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WorkCalendarResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = WorkCalendar::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|\UnitEnum|null $navigationGroup = 'Leave';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Work calendars';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $viewAnyPermission = 'leave_calendars.manage';

    protected static ?string $viewPermission = 'leave_calendars.manage';

    protected static ?string $createPermission = 'leave_calendars.manage';

    protected static ?string $updatePermission = 'leave_calendars.manage';

    public static function form(Schema $schema): Schema
    {
        return WorkCalendarForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkCalendarsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkCalendars::route('/'),
            'create' => CreateWorkCalendar::route('/create'),
            'edit' => EditWorkCalendar::route('/{record}/edit'),
        ];
    }
}
