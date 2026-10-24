<?php

namespace App\Filament\Resources\Departments\Pages;

use App\Filament\Resources\Departments\DepartmentResource;
use App\Services\OrganizationService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDepartment extends EditRecord
{
    protected static string $resource = DepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (Model $record) => app(OrganizationService::class)->deleteDepartment($record, auth()->user())),
            RestoreAction::make()
                ->using(fn (Model $record) => app(OrganizationService::class)->restoreDepartment($record->getKey(), auth()->user())),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(OrganizationService::class)->updateDepartment($record, $data, auth()->user());
    }
}
