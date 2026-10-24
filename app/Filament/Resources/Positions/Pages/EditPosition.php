<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\PositionResource;
use App\Services\OrganizationService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPosition extends EditRecord
{
    protected static string $resource = PositionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (Model $record) => app(OrganizationService::class)->deletePosition($record, auth()->user())),
            RestoreAction::make()
                ->using(fn (Model $record) => app(OrganizationService::class)->restorePosition($record->getKey(), auth()->user())),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(OrganizationService::class)->updatePosition($record, $data, auth()->user());
    }
}
