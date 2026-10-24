<?php

namespace App\Filament\Resources\ReportExports\Tables;

use App\Models\ReportExport;
use App\Services\ReportExportService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReportExportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('report_code')->label('Report')->searchable()->sortable(),
                TextColumn::make('user.name')->label('Requested by')->searchable(),
                TextColumn::make('format')->badge(),
                TextColumn::make('scope')->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ReportExport::STATUS_READY => 'success',
                        ReportExport::STATUS_FAILED => 'danger',
                        ReportExport::STATUS_PENDING => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('row_count')->numeric()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('expires_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ReportExport::STATUS_PENDING => 'Pending',
                        ReportExport::STATUS_READY => 'Ready',
                        ReportExport::STATUS_FAILED => 'Failed',
                        ReportExport::STATUS_EXPIRED => 'Expired',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('generate')
                    ->label('Generate now')
                    ->icon('heroicon-o-play')
                    ->visible(fn (ReportExport $record): bool => $record->status === ReportExport::STATUS_PENDING)
                    ->action(function (ReportExport $record): void {
                        app(ReportExportService::class)->generate((int) $record->getKey());

                        Notification::make()->success()->title('Export generation attempted')->send();
                    }),
            ]);
    }
}
