<?php

namespace App\Filament\Resources\EmployeeEmployments\Pages;

use App\Filament\Resources\EmployeeEmployments\EmployeeEmploymentResource;
use App\Models\Employee;
use App\Services\EmploymentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEmployeeEmployment extends CreateRecord
{
    protected static string $resource = EmployeeEmploymentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $employee = Employee::query()->findOrFail($data['employee_id']);
        unset($data['employee_id']);

        return app(EmploymentService::class)->create($employee, $data, auth()->user());
    }
}
