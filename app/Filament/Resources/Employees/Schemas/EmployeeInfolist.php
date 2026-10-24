<?php

namespace App\Filament\Resources\Employees\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profile')
                    ->schema([
                        TextEntry::make('employee_no')->label('Employee no.'),
                        TextEntry::make('full_name')->label('Name'),
                        TextEntry::make('work_email')->placeholder('—'),
                        TextEntry::make('phone')->placeholder('—'),
                        TextEntry::make('hire_date')->date(),
                        TextEntry::make('user.name')->label('Account')->placeholder('Not linked'),
                        TextEntry::make('user.email')->label('Account email')->placeholder('—'),
                        TextEntry::make('user.status')->label('Account status')->badge()->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Current employment')
                    ->schema([
                        TextEntry::make('currentEmployment.employment_type')->placeholder('—'),
                        TextEntry::make('currentEmployment.effective_from')->date()->placeholder('—'),
                        TextEntry::make('currentEmployment.effective_to')->date()->placeholder('Open-ended'),
                    ])
                    ->columns(3),
                Section::make('Current assignment')
                    ->schema([
                        TextEntry::make('currentAssignment.department.name')->label('Department')->placeholder('—'),
                        TextEntry::make('currentAssignment.position.name')->label('Position')->placeholder('—'),
                        TextEntry::make('currentAssignment.manager.name')->label('Manager')->placeholder('—'),
                        TextEntry::make('currentAssignment.workLocation.name')->label('Work location')->placeholder('—'),
                        TextEntry::make('currentAssignment.effective_from')->date()->placeholder('—'),
                        TextEntry::make('currentAssignment.effective_to')->date()->placeholder('Open-ended'),
                    ])
                    ->columns(3),
                Section::make('Employment history')
                    ->schema([
                        RepeatableEntry::make('employments')
                            ->schema([
                                TextEntry::make('employment_type'),
                                TextEntry::make('effective_from')->date(),
                                TextEntry::make('effective_to')->date()->placeholder('Open-ended'),
                            ])
                            ->columns(3),
                    ])
                    ->collapsed(),
                Section::make('Assignment history')
                    ->schema([
                        RepeatableEntry::make('assignments')
                            ->schema([
                                TextEntry::make('department.name')->label('Department'),
                                TextEntry::make('position.name')->label('Position'),
                                TextEntry::make('manager.name')->label('Manager')->placeholder('—'),
                                TextEntry::make('workLocation.name')->label('Location'),
                                TextEntry::make('effective_from')->date(),
                                TextEntry::make('effective_to')->date()->placeholder('Open-ended'),
                            ])
                            ->columns(3),
                    ])
                    ->collapsed(),
            ]);
    }
}
