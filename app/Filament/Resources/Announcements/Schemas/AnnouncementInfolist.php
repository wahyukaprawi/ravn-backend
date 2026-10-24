<?php

namespace App\Filament\Resources\Announcements\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AnnouncementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Announcement')
                    ->schema([
                        TextEntry::make('title')->columnSpanFull(),
                        TextEntry::make('body')->columnSpanFull(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('author.name')->label('Author'),
                        TextEntry::make('scheduled_at')->dateTime()->placeholder('—'),
                        TextEntry::make('published_at')->dateTime()->placeholder('—'),
                        TextEntry::make('archived_at')->dateTime()->placeholder('—'),
                        TextEntry::make('reads_count')
                            ->label('Reads')
                            ->state(fn ($record): int => $record->reads()->count()),
                    ])
                    ->columns(3),
                Section::make('Audiences')
                    ->schema([
                        RepeatableEntry::make('audiences')
                            ->schema([
                                TextEntry::make('audience_type')->label('Type')->badge(),
                                TextEntry::make('audience_id')->label('Target'),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }
}
