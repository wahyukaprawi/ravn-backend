<?php

namespace App\Filament\Resources\AttendanceSessions\Tables;

use App\Models\AttendanceSession;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AttendanceSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'workLocation', 'events']))
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Employee')->searchable()->sortable(),
                TextColumn::make('work_date')->date()->sortable(),
                TextColumn::make('workLocation.name')->label('Location')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === AttendanceSession::STATUS_OPEN ? 'success' : 'gray'),
                TextColumn::make('opened_at')->dateTime()->sortable(),
                TextColumn::make('closed_at')->dateTime()->placeholder('Open')->sortable(),
                TextColumn::make('late_minutes')->label('Late')->suffix(' min')->sortable(),
                TextColumn::make('early_departure_minutes')->label('Early')->suffix(' min')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        AttendanceSession::STATUS_OPEN => 'Open',
                        AttendanceSession::STATUS_CLOSED => 'Closed',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
