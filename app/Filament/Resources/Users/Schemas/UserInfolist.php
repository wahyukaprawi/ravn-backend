<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Services\MfaService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('roles.name')->label('Roles')->badge(),
                        TextEntry::make('mfa')
                            ->label('MFA')
                            ->state(fn (User $record): string => app(MfaService::class)->isEnrolled($record) ? 'Enrolled' : 'Not enrolled')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Enrolled' ? 'success' : 'warning'),
                        TextEntry::make('last_login_at')->dateTime()->placeholder('Never'),
                        TextEntry::make('activated_at')->dateTime()->placeholder('—'),
                        TextEntry::make('deactivated_at')->dateTime()->placeholder('—'),
                        TextEntry::make('created_at')->dateTime(),
                    ])
                    ->columns(3),
            ]);
    }
}
