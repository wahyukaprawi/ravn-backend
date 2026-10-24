<?php

namespace App\Filament\Resources\LeavePolicies\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LeavePoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('type'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type.name')->label('Leave type')->sortable(),
                TextColumn::make('annual_entitlement_days')->label('Entitlement')->suffix(' days')->sortable(),
                TextColumn::make('submission_deadline_days_before')->label('Deadline')->suffix(' days')->placeholder('None'),
                TextColumn::make('effective_from')->date()->sortable()->placeholder('Always'),
                TextColumn::make('effective_to')->date()->sortable()->placeholder('Open-ended'),
                IconColumn::make('active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
