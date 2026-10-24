<?php

namespace App\Filament\Resources\EmployeeAssignments\Pages;

use App\Filament\Resources\EmployeeAssignments\EmployeeAssignmentResource;
use App\Models\Employee;
use App\Services\AssignmentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEmployeeAssignment extends CreateRecord
{
    protected static string $resource = EmployeeAssignmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $employee = Employee::query()->findOrFail($data['employee_id']);
        unset($data['employee_id']);

        return app(AssignmentService::class)->create($employee, $data, auth()->user());
    }
}
