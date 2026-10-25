<?php

namespace App\Filament\Resources\NotificationDeliveries\Tables;

use App\Audit\AuditRecorder;
use App\Filament\Actions\SensitiveAction;
use App\Models\NotificationDelivery;
use App\Notifications\NotificationDeliveryService;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NotificationDeliveriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('notification'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('notification.type')->label('Type')->searchable(),
                TextColumn::make('channel')->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        NotificationDelivery::STATUS_SENT => 'success',
                        NotificationDelivery::STATUS_FAILED, NotificationDelivery::STATUS_DEAD_LETTER => 'danger',
                        NotificationDelivery::STATUS_PENDING, NotificationDelivery::STATUS_PROCESSING => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('attempts')->sortable(),
                TextColumn::make('available_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('sent_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('last_error')->limit(60)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        NotificationDelivery::STATUS_PENDING => 'Pending',
                        NotificationDelivery::STATUS_PROCESSING => 'Processing',
                        NotificationDelivery::STATUS_SENT => 'Sent',
                        NotificationDelivery::STATUS_FAILED => 'Failed',
                        NotificationDelivery::STATUS_DEAD_LETTER => 'Dead letter',
                        NotificationDelivery::STATUS_SKIPPED => 'Skipped',
                    ]),
                SelectFilter::make('channel')
                    ->options([
                        NotificationDelivery::CHANNEL_IN_APP => 'In-app',
                        NotificationDelivery::CHANNEL_EMAIL => 'Email',
                        NotificationDelivery::CHANNEL_PUSH => 'Push',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                SensitiveAction::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (NotificationDelivery $record): bool => $record->isRetryable() && auth()->user()?->can('notifications.retry'))
                    ->sensitiveAction(function (NotificationDelivery $record): void {
                        if (! app(NotificationDeliveryService::class)->retry($record)) {
                            Notification::make()
                                ->danger()
                                ->title('Delivery cannot be retried')
                                ->send();

                            return;
                        }

                        app(AuditRecorder::class)->record('notification.delivery.retried', $record, null, [
                            'notification_id' => $record->notification_id,
                            'channel' => $record->channel,
                        ], auth()->user());

                        Notification::make()->success()->title('Delivery queued for retry')->send();
                    }),
            ]);
    }
}
