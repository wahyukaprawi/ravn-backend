<?php

namespace App\Services;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Carbon;

class SessionService
{
    public const TOKEN_LIFETIME_HOURS = 8;

    public const MFA_FRESHNESS_MINUTES = 5;

    public const MFA_ENROLLMENT_TOKEN_MINUTES = 10;

    public function issueToken(User $user, string $deviceLabel, bool $mfaVerified = false): string
    {
        $expiresAt = Carbon::now()->addHours(self::TOKEN_LIFETIME_HOURS);

        $newToken = $user->createToken($deviceLabel, ['*'], $expiresAt);

        if ($mfaVerified) {
            $newToken->accessToken->forceFill(['mfa_verified_at' => now()])->save();
        }

        return $newToken->plainTextToken;
    }

    public function issueMfaEnrollmentToken(User $user, string $deviceLabel): array
    {
        $expiresAt = Carbon::now()->addMinutes(self::MFA_ENROLLMENT_TOKEN_MINUTES);

        $newToken = $user->createToken($deviceLabel, ['mfa:enroll'], $expiresAt);

        return [
            'token' => $newToken->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function completeMfaEnrollment(User $user): array
    {
        $current = $user->currentAccessToken();

        if ($current instanceof PersonalAccessToken && ! $current->can('*')) {
            $deviceLabel = $current->name;
            $current->delete();

            return [
                'token' => $this->issueToken($user, $deviceLabel, true),
                'rotated' => true,
            ];
        }

        $this->markMfaVerified($user);

        return [
            'token' => null,
            'rotated' => false,
        ];
    }

    public function revokeAll(User $user): int
    {
        return $user->tokens()->delete();
    }

    public function revokeCurrent(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    public function revokeById(User $user, int $tokenId, ?int $exceptId = null): bool
    {
        $token = $user->tokens()->whereKey($tokenId)->first();

        if ($token === null || $token->getKey() === $exceptId) {
            return false;
        }

        $token->delete();

        return true;
    }

    public function markMfaVerified(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->forceFill(['mfa_verified_at' => now()])->save();
        }
    }

    public function hasFreshMfa(User $user): bool
    {
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return false;
        }

        return $token->hasFreshMfa(self::MFA_FRESHNESS_MINUTES);
    }
}
