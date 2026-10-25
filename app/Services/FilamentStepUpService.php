<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FilamentStepUpService
{
    public const FRESHNESS_MINUTES = 5;

    public const SESSION_KEY = 'filament.mfa_step_up';

    public function __construct(
        private readonly MfaService $mfa,
        private readonly AuditRecorder $audit,
    ) {}

    public function isFresh(?User $user = null): bool
    {
        $user ??= Filament::auth()->user();

        if (! $user instanceof User || ! $this->mfa->isEnrolled($user)) {
            return false;
        }

        $state = session(self::SESSION_KEY);

        if (
            ! is_array($state)
            || ($state['user_id'] ?? null) !== $user->getKey()
            || ! is_string($state['verified_at'] ?? null)
        ) {
            return false;
        }

        $verifiedAt = Carbon::parse($state['verified_at']);

        return $verifiedAt->lessThanOrEqualTo(now())
            && $verifiedAt->copy()->addMinutes(self::FRESHNESS_MINUTES)->isFuture();
    }

    public function verifyCurrentUserOrFail(?string $code, string $errorKey = 'mfa_code'): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            throw $this->invalidCode($errorKey);
        }

        if ($this->isFresh($user)) {
            return;
        }

        $this->verifyOrFail($user, $code, $errorKey);
    }

    public function verifyOrFail(?User $user, ?string $code, string $errorKey = 'mfa_code'): void
    {
        if (! $user instanceof User || ! is_string($code) || trim($code) === '') {
            throw $this->invalidCode($errorKey);
        }

        if (! $this->mfa->isEnrolled($user)) {
            throw $this->invalidCode($errorKey);
        }

        if (! $this->mfa->verify($user, trim($code))) {
            $this->audit->record('mfa.step_up', $user, null, ['surface' => 'filament'], $user, 'failure');

            throw $this->invalidCode($errorKey);
        }

        $this->audit->record('mfa.step_up', $user, null, ['surface' => 'filament'], $user);

        $this->markFresh($user);
    }

    public function markFresh(User $user): void
    {
        session([
            self::SESSION_KEY => [
                'user_id' => $user->getKey(),
                'verified_at' => now()->toIso8601String(),
            ],
        ]);
    }

    protected function invalidCode(string $errorKey): ValidationException
    {
        return ValidationException::withMessages([
            $errorKey => 'Enter a valid authenticator or recovery code.',
        ]);
    }
}
