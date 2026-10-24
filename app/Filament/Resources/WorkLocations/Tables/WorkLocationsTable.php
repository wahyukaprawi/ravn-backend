<?php

namespace App\Filament\Resources\WorkLocations\Tables;

use App\Models\WorkLocation;
use App\Services\WorkLocationService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class WorkLocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->badge()->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('timezone')->searchable(),
                TextColumn::make('radius_meters')->suffix(' m')->sortable(),
                IconColumn::make('active')->boolean(),
                TextColumn::make('assignments_count')->counts('assignments')->label('Assignments'),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->using(fn (WorkLocation $record) => app(WorkLocationService::class)->delete($record, auth()->user())),
                RestoreAction::make()
                    ->using(fn (WorkLocation $record) => app(WorkLocationService::class)->restore($record->getKey(), auth()->user())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(function ($records): void {
                            $service = app(WorkLocationService::class);

                            foreach ($records as $record) {
                                $service->delete($record, auth()->user());
                            }
                        }),
                ]),
            ]);
    }
}
