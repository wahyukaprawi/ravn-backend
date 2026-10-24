<?php

namespace App\Filament\Resources\ReportExports\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReportExportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Export')
                    ->schema([
                        TextEntry::make('id')->label('Export #'),
                        TextEntry::make('report_code')->label('Report'),
                        TextEntry::make('user.name')->label('Requested by'),
                        TextEntry::make('format')->badge(),
                        TextEntry::make('scope')->badge(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('row_count'),
                        TextEntry::make('checksum')->placeholder('—'),
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('expires_at')->dateTime()->placeholder('—'),
                        TextEntry::make('last_error')->columnSpanFull()->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make('Filters')
                    ->schema([
                        KeyValueEntry::make('filters')->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }
}
