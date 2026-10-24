<?php

namespace App\Filament\Resources\WorkCalendars\Pages;

use App\Filament\Resources\WorkCalendars\WorkCalendarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkCalendars extends ListRecords
{
    protected static string $resource = WorkCalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
