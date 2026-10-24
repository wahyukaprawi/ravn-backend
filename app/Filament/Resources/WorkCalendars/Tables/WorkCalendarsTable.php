<?php

namespace App\Filament\Resources\WorkCalendars\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkCalendarsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('timezone')->searchable(),
                TextColumn::make('days_count')->counts('days')->label('Exceptions'),
                IconColumn::make('active')->boolean(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
