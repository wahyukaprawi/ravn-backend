<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthenticationService
{
    public function __construct(
        private readonly MfaService $mfa,
    ) {}

    public function attempt(string $email, string $password): ?User
    {
        $user = User::query()->where('email', $this->normalizeEmail($email))->first();

        if ($user === null) {
            Hash::make($password);

            return null;
        }

        if (! $user->canAuthenticate()) {
            return null;
        }

        if (! Hash::check($password, (string) $user->password)) {
            return null;
        }

        return $user;
    }

    public function recordLogin(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->forceFill(['last_login_at' => now()])->save();
        });
    }

    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function mfaRequired(User $user): bool
    {
        return $this->mfa->requiresMfa($user) && $this->mfa->isEnrolled($user);
    }

    public function mfaEnrollmentRequired(User $user): bool
    {
        return $this->mfa->requiresMfa($user) && ! $this->mfa->isEnrolled($user);
    }
}
