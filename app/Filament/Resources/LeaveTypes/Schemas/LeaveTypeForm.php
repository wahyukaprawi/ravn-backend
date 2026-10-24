<?php

namespace App\Filament\Resources\LeaveTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeaveTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Leave type')
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(60)
                            ->alphaDash()
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(150),
                        Toggle::make('requires_approval')
                            ->default(true),
                        Toggle::make('active')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
