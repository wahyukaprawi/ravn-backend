<?php

namespace App\Filament\Resources\LeaveRequests\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeaveRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request')
                    ->schema([
                        TextEntry::make('id')->label('Request #'),
                        TextEntry::make('user.name')->label('Employee'),
                        TextEntry::make('type.name')->label('Leave type'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('start_date')->date(),
                        TextEntry::make('end_date')->date(),
                        TextEntry::make('days')->numeric(decimalPlaces: 1),
                        TextEntry::make('manager.name')->label('Manager')->placeholder('—'),
                        TextEntry::make('submitted_at')->dateTime(),
                        TextEntry::make('reason')->placeholder('No reason provided')->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('Approval steps')
                    ->schema([
                        RepeatableEntry::make('workflow.steps')
                            ->schema([
                                TextEntry::make('step_key')->label('Step'),
                                TextEntry::make('status')->badge(),
                                TextEntry::make('assignee_type')->label('Assignee type')->placeholder('—'),
                                TextEntry::make('assignee_id')->label('Assignee ID')->placeholder('—'),
                                TextEntry::make('acted_at')->dateTime()->placeholder('—'),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn ($record): bool => $record?->workflow !== null)
                    ->collapsed(),
            ]);
    }
}
