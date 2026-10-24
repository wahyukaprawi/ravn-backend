<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Audit entry')
                    ->schema([
                        TextEntry::make('id')->label('Entry #'),
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('actor.name')->label('Actor')->placeholder('System'),
                        TextEntry::make('action'),
                        TextEntry::make('outcome')->badge(),
                        TextEntry::make('subject_type')->placeholder('—'),
                        TextEntry::make('subject_id')->placeholder('—'),
                        TextEntry::make('request_id')->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make('Before')
                    ->schema([
                        KeyValueEntry::make('before')->columnSpanFull(),
                    ])
                    ->collapsed(),
                Section::make('After')
                    ->schema([
                        KeyValueEntry::make('after')->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }
}
