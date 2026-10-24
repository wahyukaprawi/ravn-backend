<?php

namespace App\Filament\Resources\WorkLocations\Pages;

use App\Filament\Resources\WorkLocations\WorkLocationResource;
use App\Services\WorkLocationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWorkLocation extends CreateRecord
{
    protected static string $resource = WorkLocationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(WorkLocationService::class)->create($data, auth()->user());
    }
}
