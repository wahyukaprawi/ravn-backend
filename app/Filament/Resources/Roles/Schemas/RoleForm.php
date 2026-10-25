<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Services\FilamentStepUpService;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Role')
                    ->schema([
                        TextInput::make('name')
                            ->disabled()
                            ->dehydrated(false),
                        CheckboxList::make('permissions')
                            ->options(fn (): array => Permission::query()->orderBy('name')->pluck('name', 'name')->all())
                            ->columns(3)
                            ->searchable()
                            ->bulkToggleable()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Confirmation')
                    ->schema([
                        TextInput::make('mfa_code')
                            ->label('Authentication code')
                            ->password()
                            ->visible(fn (): bool => ! app(FilamentStepUpService::class)->isFresh())
                            ->required(fn (): bool => ! app(FilamentStepUpService::class)->isFresh())
                            ->autocomplete('one-time-code')
                            ->helperText('Enter a 6-digit authenticator code or a recovery code to save permission changes.'),
                    ]),
            ]);
    }
}
