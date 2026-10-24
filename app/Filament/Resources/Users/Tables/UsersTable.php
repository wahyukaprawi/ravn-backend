<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Services\MfaService;
use App\Services\UserAdministrationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('roles'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        User::STATUS_ACTIVE => 'success',
                        User::STATUS_PENDING_ACTIVATION => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('roles.name')->label('Roles')->badge(),
                TextColumn::make('last_login_at')->dateTime()->placeholder('Never')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        User::STATUS_PENDING_ACTIVATION => 'Pending activation',
                        User::STATUS_ACTIVE => 'Active',
                        User::STATUS_INACTIVE => 'Inactive',
                    ]),
                SelectFilter::make('role')
                    ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                    ->query(fn ($query, array $data) => filled($data['value'])
                        ? $query->whereHas('roles', fn ($roles) => $roles->where('name', $data['value']))
                        : $query),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('users.update')),
                Action::make('assignRoles')
                    ->label('Roles')
                    ->icon('heroicon-o-key')
                    ->visible(fn (): bool => auth()->user()?->can('users.assign_roles'))
                    ->fillForm(fn (User $record): array => [
                        'roles' => $record->getRoleNames()->all(),
                    ])
                    ->schema([
                        CheckboxList::make('roles')
                            ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                            ->required(),
                    ])
                    ->action(function (User $record, array $data): void {
                        try {
                            app(UserAdministrationService::class)->assignRoles(auth()->user(), $record, $data['roles'] ?? []);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Roles could not be updated')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Roles updated')->send();
                    }),
                Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->status === User::STATUS_ACTIVE
                        && ! auth()->user()->is($record)
                        && auth()->user()?->can('users.deactivate'))
                    ->action(function (User $record): void {
                        try {
                            app(UserAdministrationService::class)->deactivate(auth()->user(), $record);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('User could not be deactivated')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('User deactivated')->send();
                    }),
                Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->status === User::STATUS_INACTIVE
                        && auth()->user()?->can('users.activate'))
                    ->action(function (User $record): void {
                        app(UserAdministrationService::class)->reactivate(auth()->user(), $record);

                        Notification::make()->success()->title('User reactivated')->send();
                    }),
                Action::make('revokeSessions')
                    ->label('Revoke sessions')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => auth()->user()?->can('users.revoke_sessions'))
                    ->action(function (User $record): void {
                        $revoked = app(UserAdministrationService::class)->revokeSessions(auth()->user(), $record);

                        Notification::make()->success()->title("Revoked {$revoked} session(s)")->send();
                    }),
                Action::make('resetMfa')
                    ->label('Reset MFA')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This removes the authenticator and recovery codes and revokes all sessions. The user must enrol again at next login.')
                    ->visible(fn (User $record): bool => ! auth()->user()->is($record)
                        && auth()->user()?->can('users.reset_mfa')
                        && app(MfaService::class)->isEnrolled($record))
                    ->action(function (User $record): void {
                        try {
                            app(UserAdministrationService::class)->resetMfa(auth()->user(), $record);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('MFA could not be reset')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('MFA reset')->send();
                    }),
            ]);
    }
}
