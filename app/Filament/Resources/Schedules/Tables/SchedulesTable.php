<?php

namespace App\Filament\Resources\Schedules\Tables;

use App\Models\Schedule;
use App\Services\AttendanceScheduleService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'location']))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('user.name')->label('Employee')->searchable()->sortable(),
                TextColumn::make('location.name')->label('Location')->sortable(),
                TextColumn::make('days')
                    ->formatStateUsing(fn ($state): string => collect($state ?? [])
                        ->map(fn ($day): string => Schedule::DAYS[(int) $day] ?? (string) $day)
                        ->implode(', '))
                    ->wrap(),
                TextColumn::make('start_time')->label('Start'),
                TextColumn::make('end_time')->label('End'),
                TextColumn::make('effective_from')->date()->sortable(),
                TextColumn::make('effective_to')->date()->placeholder('Open-ended')->sortable(),
                IconColumn::make('active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('work_location_id')
                    ->relationship('location', 'name')
                    ->label('Location'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->using(fn (Schedule $record) => app(AttendanceScheduleService::class)->delete($record, auth()->user())),
            ]);
    }
}
