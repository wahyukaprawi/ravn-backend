<?php

namespace App\Filament\Resources\EmployeeAssignments\Schemas;

use App\Models\Employee;
use App\Models\EmployeeEmployment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EmployeeAssignmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Employee and period')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Employee')
                            ->relationship('employee', 'employee_no')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record): string => $record->employee_no.' — '.$record->full_name)
                            ->searchable(['employee_no', 'first_name', 'last_name'])
                            ->preload()
                            ->required()
                            ->live()
                            ->disabledOn('edit'),
                        Select::make('employee_employment_id')
                            ->label('Employment')
                            ->options(fn (Get $get): array => EmployeeEmployment::query()
                                ->where('employee_id', $get('employee_id'))
                                ->orderByDesc('effective_from')
                                ->get()
                                ->mapWithKeys(fn (EmployeeEmployment $employment): array => [
                                    $employment->getKey() => $employment->employment_type.' · '.$employment->effective_from->toDateString().' → '.($employment->effective_to?->toDateString() ?? 'open'),
                                ])
                                ->all())
                            ->required(),
                        DatePicker::make('effective_from')
                            ->required()
                            ->native(false),
                        DatePicker::make('effective_to')
                            ->native(false),
                    ])
                    ->columns(2),
                Section::make('Organization')
                    ->schema([
                        Select::make('department_id')
                            ->relationship('department', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('position_id')
                            ->relationship('position', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('manager_id')
                            ->label('Manager')
                            ->relationship('manager', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('work_location_id')
                            ->label('Work location')
                            ->relationship('workLocation', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }
}
