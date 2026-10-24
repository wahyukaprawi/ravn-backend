<?php

namespace App\Filament\Resources\EmployeeEmployments\Pages;

use App\Filament\Resources\EmployeeEmployments\EmployeeEmploymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeEmployments extends ListRecords
{
    protected static string $resource = EmployeeEmploymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
