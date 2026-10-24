<?php

namespace App\Filament\Resources\Schedules\Schemas;

use App\Models\Schedule;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Assignment')
                    ->schema([
                        Select::make('user_id')
                            ->label('Employee account')
                            ->relationship('user', 'name', modifyQueryUsing: fn ($query) => $query->where('status', 'active'))
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('work_location_id')
                            ->label('Work location')
                            ->relationship('location', 'name', modifyQueryUsing: fn ($query) => $query->where('active', true))
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(120),
                        Toggle::make('active')->default(true),
                    ])
                    ->columns(2),
                Section::make('Effective period')
                    ->schema([
                        DatePicker::make('effective_from')->required()->native(false),
                        DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
                    ])
                    ->columns(2),
                Section::make('Shift')
                    ->schema([
                        CheckboxList::make('days')
                            ->options(Schedule::DAYS)
                            ->columns(4)
                            ->required(),
                        TimePicker::make('start_time')->required()->seconds(false),
                        TimePicker::make('end_time')->required()->seconds(false),
                        TextInput::make('late_grace_minutes')->integer()->minValue(0)->maxValue(120)->default(0)->suffix('min'),
                        TextInput::make('early_departure_grace_minutes')->integer()->minValue(0)->maxValue(120)->default(0)->suffix('min'),
                        Toggle::make('overnight'),
                    ])
                    ->columns(2),
            ]);
    }
}
