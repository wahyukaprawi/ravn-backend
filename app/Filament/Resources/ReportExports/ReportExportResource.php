<?php

namespace App\Filament\Resources\ReportExports;

use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\ReportExports\Pages\ListReportExports;
use App\Filament\Resources\ReportExports\Pages\ViewReportExport;
use App\Filament\Resources\ReportExports\Schemas\ReportExportInfolist;
use App\Filament\Resources\ReportExports\Tables\ReportExportsTable;
use App\Models\ReportExport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ReportExportResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = ReportExport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Report exports';

    protected static ?string $viewAnyPermission = 'reports.manage_exports';

    protected static ?string $viewPermission = 'reports.manage_exports';

    public static function infolist(Schema $schema): Schema
    {
        return ReportExportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReportExportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportExports::route('/'),
            'view' => ViewReportExport::route('/{record}'),
        ];
    }
}
