<?php

namespace App\Filament\Resources\WorkLocations\Pages;

use App\Filament\Resources\WorkLocations\WorkLocationResource;
use App\Services\WorkLocationService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditWorkLocation extends EditRecord
{
    protected static string $resource = WorkLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (Model $record) => app(WorkLocationService::class)->delete($record, auth()->user())),
            RestoreAction::make()
                ->using(fn (Model $record) => app(WorkLocationService::class)->restore($record->getKey(), auth()->user())),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(WorkLocationService::class)->update($record, $data, auth()->user());
    }
}
