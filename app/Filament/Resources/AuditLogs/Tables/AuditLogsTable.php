<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('actor'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('actor.name')->label('Actor')->placeholder('System')->searchable(),
                TextColumn::make('action')->searchable()->sortable(),
                TextColumn::make('subject_type')->label('Subject type')->toggleable(),
                TextColumn::make('subject_id')->label('Subject ID')->toggleable(),
                TextColumn::make('outcome')
                    ->badge()
                    ->color(fn (string $state): string => $state === AuditLog::OUTCOME_SUCCESS ? 'success' : 'danger'),
                TextColumn::make('request_id')->limit(12)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('outcome')
                    ->options([
                        AuditLog::OUTCOME_SUCCESS => 'Success',
                        AuditLog::OUTCOME_FAILURE => 'Failure',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
