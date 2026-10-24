<?php

namespace App\Filament\Resources\ReportExports\Pages;

use App\Filament\Resources\ReportExports\ReportExportResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewReportExport extends ViewRecord
{
    protected static string $resource = ReportExportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
