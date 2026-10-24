<?php

namespace App\Filament\Resources\WorkCalendars\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkCalendarForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Calendar')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(150),
                        TextInput::make('timezone')
                            ->required()
                            ->maxLength(64)
                            ->datalist(fn (): array => timezone_identifiers_list()),
                        Toggle::make('active')->default(true),
                    ])
                    ->columns(2),
                Section::make('Holidays and exceptions')
                    ->schema([
                        Repeater::make('days')
                            ->relationship()
                            ->schema([
                                DatePicker::make('date')->required()->native(false),
                                Select::make('type')
                                    ->options(['holiday' => 'Holiday', 'working_day' => 'Working day'])
                                    ->required(),
                                TextInput::make('name')->maxLength(150),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->orderColumn('date')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
