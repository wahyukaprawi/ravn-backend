<?php

namespace App\Filament\Resources\WorkLocations\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkLocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Location')
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(60)
                            ->alphaDash()
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(150),
                        TextInput::make('timezone')
                            ->required()
                            ->maxLength(64)
                            ->datalist(fn (): array => timezone_identifiers_list()),
                        Toggle::make('active')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Address')
                    ->schema([
                        Textarea::make('address')
                            ->required()
                            ->maxLength(500)
                            ->columnSpanFull(),
                        TextInput::make('remote_address')
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),
                Section::make('Geofence')
                    ->schema([
                        TextInput::make('latitude')
                            ->required()
                            ->numeric()
                            ->step('0.0000001')
                            ->minValue(-90)->maxValue(90),
                        TextInput::make('longitude')
                            ->required()
                            ->numeric()
                            ->step('0.0000001')
                            ->minValue(-180)->maxValue(180),
                        TextInput::make('radius_meters')
                            ->required()
                            ->integer()
                            ->minValue(10)
                            ->maxValue(5000)
                            ->default(100),
                    ])
                    ->columns(3),
            ]);
    }
}
