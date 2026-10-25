<?php

namespace App\Filament\Actions;

use App\Services\FilamentStepUpService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SensitiveAction extends Action
{
    protected ?Closure $sensitiveAction = null;

    public function sensitiveAction(Closure $action): static
    {
        $this->sensitiveAction = $action;

        $this->action(function (array $data): mixed {
            $errorKey = 'mountedActions.'.($this->getNestingIndex() ?? 0).'.data.mfa_code';

            app(FilamentStepUpService::class)->verifyCurrentUserOrFail($data['mfa_code'] ?? null, $errorKey);

            unset($data['mfa_code']);

            return $this->evaluate($this->sensitiveAction, ['data' => $data]);
        });

        return $this;
    }

    public function getSchema(Schema $schema): ?Schema
    {
        $schema = parent::getSchema($schema) ?? $schema;

        if ($schema->getComponent('mfa_code') !== null) {
            return $schema;
        }

        return $schema->components([
            ...$schema->getComponents(),
            TextInput::make('mfa_code')
                ->label('Authentication code')
                ->password()
                ->visible(fn (): bool => ! app(FilamentStepUpService::class)->isFresh())
                ->required(fn (): bool => ! app(FilamentStepUpService::class)->isFresh())
                ->autocomplete('one-time-code')
                ->helperText('Enter a 6-digit authenticator code or a recovery code.'),
        ]);
    }
}
