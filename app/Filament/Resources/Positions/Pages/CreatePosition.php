<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\PositionResource;
use App\Services\OrganizationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePosition extends CreateRecord
{
    protected static string $resource = PositionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(OrganizationService::class)->createPosition($data, auth()->user());
    }
}
