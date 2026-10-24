<?php

namespace App\Filament\Resources\AttendanceCorrections\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AttendanceCorrectionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Correction')
                    ->schema([
                        TextEntry::make('id')->label('Request #'),
                        TextEntry::make('session.user.name')->label('Employee'),
                        TextEntry::make('session.work_date')->label('Work date')->date(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('requester.name')->label('Requested by'),
                        TextEntry::make('approver.name')->label('Approver')->placeholder('—'),
                        TextEntry::make('reason')->columnSpanFull(),
                        TextEntry::make('submitted_at')->dateTime()->placeholder('—'),
                        TextEntry::make('decided_at')->dateTime()->placeholder('—'),
                        TextEntry::make('executed_at')->dateTime()->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make('Proposed changes')
                    ->schema([
                        KeyValueEntry::make('changes')->columnSpanFull(),
                    ]),
                Section::make('Before')
                    ->schema([
                        KeyValueEntry::make('before')->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }
}
