<?php

namespace App\Filament\Resources\EmployeeEmployments\Pages;

use App\Filament\Resources\EmployeeEmployments\EmployeeEmploymentResource;
use App\Services\EmploymentService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditEmployeeEmployment extends EditRecord
{
    protected static string $resource = EmployeeEmploymentResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        unset($data['employee_id']);

        return app(EmploymentService::class)->update($record, $data, auth()->user());
    }
}
