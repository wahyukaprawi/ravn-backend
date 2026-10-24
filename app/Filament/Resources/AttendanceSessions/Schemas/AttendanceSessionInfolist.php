<?php

namespace App\Filament\Resources\AttendanceSessions\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AttendanceSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Session')
                    ->schema([
                        TextEntry::make('user.name')->label('Employee'),
                        TextEntry::make('work_date')->date(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('workLocation.name')->label('Location')->placeholder('—'),
                        TextEntry::make('timezone'),
                        TextEntry::make('opened_at')->dateTime(),
                        TextEntry::make('closed_at')->dateTime()->placeholder('Open'),
                        TextEntry::make('late_minutes')->label('Late')->suffix(' min'),
                        TextEntry::make('early_departure_minutes')->label('Early departure')->suffix(' min'),
                        TextEntry::make('scheduled_start_at')->dateTime()->placeholder('—'),
                        TextEntry::make('scheduled_end_at')->dateTime()->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make('Events')
                    ->schema([
                        RepeatableEntry::make('events')
                            ->schema([
                                TextEntry::make('event_type')->badge(),
                                TextEntry::make('occurred_at')->dateTime(),
                                TextEntry::make('verification_status')->badge(),
                                TextEntry::make('distance_meters')->label('Distance')->suffix(' m'),
                                TextEntry::make('flags')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', $state) : (string) $state)
                                    ->placeholder('—'),
                            ])
                            ->columns(5),
                    ])
                    ->collapsed(),
            ]);
    }
}
