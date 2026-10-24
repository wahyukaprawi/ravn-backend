<?php

namespace App\Filament\Resources\LeavePolicies\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeavePolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Policy')
                    ->schema([
                        Select::make('leave_type_id')
                            ->label('Leave type')
                            ->relationship('type', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(150),
                        TextInput::make('annual_entitlement_days')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(365)
                            ->suffix('days'),
                        TextInput::make('submission_deadline_days_before')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(365)
                            ->suffix('days'),
                        DatePicker::make('effective_from')->native(false),
                        DatePicker::make('effective_to')
                            ->native(false)
                            ->afterOrEqual('effective_from'),
                        Toggle::make('active')->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
