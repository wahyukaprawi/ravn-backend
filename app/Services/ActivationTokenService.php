<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivationTokenService
{
    public const TTL_HOURS = 24;

    public const EXPIRY_DAYS = 7;

    public function issue(User $user): string
    {
        return DB::transaction(function () use ($user) {
            $user->activationTokens()
                ->whereNull('used_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            $plain = Str::random(64);

            $user->activationTokens()->create([
                'token_hash' => $this->hash($plain),
                'expires_at' => now()->addHours(self::TTL_HOURS),
                'created_at' => now(),
            ]);

            $user->forceFill([
                'invitation_expires_at' => now()->addDays(self::EXPIRY_DAYS),
            ])->save();

            return $plain;
        });
    }

    public function findUsable(string $plain): ?UserActivationToken
    {
        $token = UserActivationToken::query()
            ->where('token_hash', $this->hash($plain))
            ->first();

        if ($token === null || ! $token->isUsable()) {
            return null;
        }

        return $token;
    }

    public function consume(UserActivationToken $token): void
    {
        $token->forceFill(['used_at' => now()])->save();
    }

    public function invalidateFor(User $user): void
    {
        $user->activationTokens()
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => now()]);
    }

    public function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
