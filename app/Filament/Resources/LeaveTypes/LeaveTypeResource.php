<?php

namespace App\Filament\Resources\LeaveTypes;

use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\LeaveTypes\Pages\CreateLeaveType;
use App\Filament\Resources\LeaveTypes\Pages\EditLeaveType;
use App\Filament\Resources\LeaveTypes\Pages\ListLeaveTypes;
use App\Filament\Resources\LeaveTypes\Schemas\LeaveTypeForm;
use App\Filament\Resources\LeaveTypes\Tables\LeaveTypesTable;
use App\Models\LeaveType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LeaveTypeResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = LeaveType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'Leave';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Leave types';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $viewAnyPermission = 'leave_policies.view';

    protected static ?string $viewPermission = 'leave_policies.view';

    protected static ?string $createPermission = 'leave_policies.manage';

    protected static ?string $updatePermission = 'leave_policies.manage';

    public static function form(Schema $schema): Schema
    {
        return LeaveTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveTypes::route('/'),
            'create' => CreateLeaveType::route('/create'),
            'edit' => EditLeaveType::route('/{record}/edit'),
        ];
    }
}
