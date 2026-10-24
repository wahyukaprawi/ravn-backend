<?php

namespace App\Filament\Resources\LeavePolicies\Pages;

use App\Filament\Resources\LeavePolicies\LeavePolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLeavePolicies extends ListRecords
{
    protected static string $resource = LeavePolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
