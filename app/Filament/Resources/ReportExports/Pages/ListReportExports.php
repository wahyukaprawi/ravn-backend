<?php

namespace App\Filament\Resources\ReportExports\Pages;

use App\Filament\Resources\ReportExports\ReportExportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReportExports extends ListRecords
{
    protected static string $resource = ReportExportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
