<?php

namespace App\Filament\Resources\EmployeeAssignments;

use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\EmployeeAssignments\Pages\CreateEmployeeAssignment;
use App\Filament\Resources\EmployeeAssignments\Pages\EditEmployeeAssignment;
use App\Filament\Resources\EmployeeAssignments\Pages\ListEmployeeAssignments;
use App\Filament\Resources\EmployeeAssignments\Schemas\EmployeeAssignmentForm;
use App\Filament\Resources\EmployeeAssignments\Tables\EmployeeAssignmentsTable;
use App\Models\EmployeeAssignment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EmployeeAssignmentResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = EmployeeAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Organization';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Assignments';

    protected static ?string $modelLabel = 'assignment';

    protected static ?string $viewAnyPermission = 'assignments.view';

    protected static ?string $viewPermission = 'assignments.view';

    protected static ?string $createPermission = 'assignments.create';

    protected static ?string $updatePermission = 'assignments.update';

    public static function form(Schema $schema): Schema
    {
        return EmployeeAssignmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeAssignmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeAssignments::route('/'),
            'create' => CreateEmployeeAssignment::route('/create'),
            'edit' => EditEmployeeAssignment::route('/{record}/edit'),
        ];
    }
}
