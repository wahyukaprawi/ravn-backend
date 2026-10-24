<?php

namespace App\Filament\Resources\WorkLocations;

use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\WorkLocations\Pages\CreateWorkLocation;
use App\Filament\Resources\WorkLocations\Pages\EditWorkLocation;
use App\Filament\Resources\WorkLocations\Pages\ListWorkLocations;
use App\Filament\Resources\WorkLocations\Pages\ViewWorkLocation;
use App\Filament\Resources\WorkLocations\Schemas\WorkLocationForm;
use App\Filament\Resources\WorkLocations\Schemas\WorkLocationInfolist;
use App\Filament\Resources\WorkLocations\Tables\WorkLocationsTable;
use App\Models\WorkLocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WorkLocationResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = WorkLocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|\UnitEnum|null $navigationGroup = 'Organization';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $viewAnyPermission = 'work_locations.view_any';

    protected static ?string $viewPermission = 'work_locations.view';

    protected static ?string $createPermission = 'work_locations.create';

    protected static ?string $updatePermission = 'work_locations.update';

    protected static ?string $deletePermission = 'work_locations.delete';

    public static function form(Schema $schema): Schema
    {
        return WorkLocationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WorkLocationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkLocationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkLocations::route('/'),
            'create' => CreateWorkLocation::route('/create'),
            'view' => ViewWorkLocation::route('/{record}'),
            'edit' => EditWorkLocation::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
