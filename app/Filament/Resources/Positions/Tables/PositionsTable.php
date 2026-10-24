<?php

namespace App\Filament\Resources\Positions\Tables;

use App\Models\Position;
use App\Services\OrganizationService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PositionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('assignments_count')
                    ->counts('assignments')
                    ->label('Assignments'),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->using(fn (Position $record) => app(OrganizationService::class)->deletePosition($record, auth()->user())),
                RestoreAction::make()
                    ->using(fn (Position $record) => app(OrganizationService::class)->restorePosition($record->getKey(), auth()->user())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(function ($records): void {
                            $service = app(OrganizationService::class);

                            foreach ($records as $record) {
                                $service->deletePosition($record, auth()->user());
                            }
                        }),
                ]),
            ]);
    }
}
