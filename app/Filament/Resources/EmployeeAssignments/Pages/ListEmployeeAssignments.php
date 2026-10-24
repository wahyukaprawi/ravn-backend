<?php

namespace App\Filament\Resources\EmployeeAssignments\Pages;

use App\Filament\Resources\EmployeeAssignments\EmployeeAssignmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeAssignments extends ListRecords
{
    protected static string $resource = EmployeeAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
