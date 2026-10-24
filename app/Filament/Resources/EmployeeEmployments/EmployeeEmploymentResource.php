<?php

namespace App\Filament\Resources\EmployeeEmployments;

use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\EmployeeEmployments\Pages\CreateEmployeeEmployment;
use App\Filament\Resources\EmployeeEmployments\Pages\EditEmployeeEmployment;
use App\Filament\Resources\EmployeeEmployments\Pages\ListEmployeeEmployments;
use App\Filament\Resources\EmployeeEmployments\Schemas\EmployeeEmploymentForm;
use App\Filament\Resources\EmployeeEmployments\Tables\EmployeeEmploymentsTable;
use App\Models\EmployeeEmployment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EmployeeEmploymentResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = EmployeeEmployment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|\UnitEnum|null $navigationGroup = 'Organization';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Employments';

    protected static ?string $modelLabel = 'employment';

    protected static ?string $viewAnyPermission = 'employments.view';

    protected static ?string $viewPermission = 'employments.view';

    protected static ?string $createPermission = 'employments.create';

    protected static ?string $updatePermission = 'employments.update';

    public static function form(Schema $schema): Schema
    {
        return EmployeeEmploymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeEmploymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeEmployments::route('/'),
            'create' => CreateEmployeeEmployment::route('/create'),
            'edit' => EditEmployeeEmployment::route('/{record}/edit'),
        ];
    }
}
