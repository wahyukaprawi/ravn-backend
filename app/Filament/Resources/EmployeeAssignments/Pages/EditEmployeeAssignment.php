<?php

namespace App\Filament\Resources\EmployeeAssignments\Pages;

use App\Filament\Resources\EmployeeAssignments\EmployeeAssignmentResource;
use App\Services\AssignmentService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditEmployeeAssignment extends EditRecord
{
    protected static string $resource = EmployeeAssignmentResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        unset($data['employee_id']);

        return app(AssignmentService::class)->update($record, $data, auth()->user());
    }
}
