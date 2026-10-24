<?php

namespace App\Filament\Resources\EmployeeEmployments\Schemas;

use App\Models\Employee;
use App\Models\EmployeeEmployment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmployeeEmploymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Employment')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Employee')
                            ->relationship('employee', 'employee_no')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record): string => $record->employee_no.' — '.$record->full_name)
                            ->searchable(['employee_no', 'first_name', 'last_name'])
                            ->preload()
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('employment_type')
                            ->options(array_combine(EmployeeEmployment::TYPES, EmployeeEmployment::TYPES))
                            ->required(),
                        DatePicker::make('effective_from')
                            ->required()
                            ->native(false),
                        DatePicker::make('effective_to')
                            ->native(false),
                    ])
                    ->columns(2),
            ]);
    }
}
