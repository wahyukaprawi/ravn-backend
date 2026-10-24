<?php

namespace App\Filament\Resources\Employees\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->schema([
                        TextInput::make('employee_no')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Select::make('user_id')
                            ->label('Linked account')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->unique(ignoreRecord: true),
                        TextInput::make('first_name')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('last_name')
                            ->maxLength(100),
                    ])
                    ->columns(2),
                Section::make('Contact and employment')
                    ->schema([
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(30),
                        TextInput::make('work_email')
                            ->email()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        DatePicker::make('hire_date')
                            ->required()
                            ->native(false),
                    ])
                    ->columns(3),
            ]);
    }
}
