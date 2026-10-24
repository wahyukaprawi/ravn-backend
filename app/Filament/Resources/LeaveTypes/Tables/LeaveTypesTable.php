<?php

namespace App\Filament\Resources\LeaveTypes\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LeaveTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->badge()->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                IconColumn::make('requires_approval')->boolean(),
                IconColumn::make('active')->boolean(),
                TextColumn::make('policies_count')->counts('policies')->label('Policies'),
                TextColumn::make('balances_count')->counts('balances')->label('Balances')->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
