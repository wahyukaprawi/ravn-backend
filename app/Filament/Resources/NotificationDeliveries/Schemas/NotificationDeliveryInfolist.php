<?php

namespace App\Filament\Resources\NotificationDeliveries\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NotificationDeliveryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Delivery')
                    ->schema([
                        TextEntry::make('id')->label('Delivery #'),
                        TextEntry::make('notification.type')->label('Notification type'),
                        TextEntry::make('channel')->badge(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('attempts'),
                        TextEntry::make('available_at')->dateTime()->placeholder('—'),
                        TextEntry::make('sent_at')->dateTime()->placeholder('—'),
                        TextEntry::make('dead_lettered_at')->dateTime()->placeholder('—'),
                        TextEntry::make('last_error')->columnSpanFull()->placeholder('—'),
                        TextEntry::make('last_error_code')->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make('Notification payload')
                    ->schema([
                        KeyValueEntry::make('notification.data')->columnSpanFull(),
                    ]),
            ]);
    }
}
