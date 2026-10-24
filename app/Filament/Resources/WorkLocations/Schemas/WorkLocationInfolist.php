<?php

namespace App\Filament\Resources\WorkLocations\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkLocationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Location')
                    ->schema([
                        TextEntry::make('code'),
                        TextEntry::make('name'),
                        TextEntry::make('timezone'),
                        TextEntry::make('active')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
                    ])
                    ->columns(2),
                Section::make('Address')
                    ->schema([
                        TextEntry::make('address')->columnSpanFull(),
                        TextEntry::make('remote_address')->columnSpanFull()->placeholder('—'),
                    ]),
                Section::make('Geofence')
                    ->schema([
                        TextEntry::make('latitude'),
                        TextEntry::make('longitude'),
                        TextEntry::make('radius_meters')->suffix(' m'),
                    ])
                    ->columns(3),
            ]);
    }
}
