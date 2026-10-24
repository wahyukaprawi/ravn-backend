<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserMfaMethod;
use App\Models\UserMfaRecoveryCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class MfaService
{
    public const RECOVERY_CODE_COUNT = 8;

    public const WINDOW = 1;

    public function __construct(
        private readonly Google2FA $google2fa = new Google2FA,
    ) {}

    public function enroll(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $secret = $this->google2fa->generateSecretKey();

            $method = UserMfaMethod::query()->updateOrCreate(
                ['user_id' => $user->id, 'type' => UserMfaMethod::TYPE_TOTP],
                ['secret' => $secret, 'confirmed_at' => null, 'last_used_at' => null],
            );

            return [
                'secret' => $secret,
                'otpauth_url' => $this->google2fa->getQRCodeUrl(
                    config('app.name', 'Ravn'),
                    $user->email,
                    $secret,
                ),
            ];
        });
    }

    public function confirm(User $user, string $code): ?array
    {
        $method = $this->methodFor($user);

        if ($method === null || ! $this->verifyCode($method, $code)) {
            return null;
        }

        return DB::transaction(function () use ($method, $user) {
            $method->forceFill([
                'confirmed_at' => now(),
                'last_used_at' => now(),
            ])->save();

            $codes = $this->generateRecoveryCodes($user);

            return ['recovery_codes' => $codes];
        });
    }

    public function verify(User $user, string $code): bool
    {
        $method = $this->methodFor($user);

        if ($method === null || ! $method->isConfirmed()) {
            return false;
        }

        if (! $this->verifyCode($method, $code)) {
            return $this->consumeRecoveryCode($user, $code);
        }

        return true;
    }

    public function isEnrolled(User $user): bool
    {
        return $this->methodFor($user)?->isConfirmed() ?? false;
    }

    public function requiresMfa(User $user): bool
    {
        return $user->hasRole('super_admin') || $user->hasRole('hr_admin');
    }

    public function methodFor(User $user): ?UserMfaMethod
    {
        return UserMfaMethod::query()
            ->where('user_id', $user->id)
            ->where('type', UserMfaMethod::TYPE_TOTP)
            ->first();
    }

    public function generateRecoveryCodes(User $user): array
    {
        UserMfaRecoveryCode::query()->where('user_id', $user->id)->delete();

        $plainCodes = [];

        foreach (range(1, self::RECOVERY_CODE_COUNT) as $ignored) {
            $plain = Str::upper(Str::random(10));

            UserMfaRecoveryCode::query()->create([
                'user_id' => $user->id,
                'code_hash' => $this->hashRecoveryCode($plain),
                'created_at' => now(),
            ]);

            $plainCodes[] = $plain;
        }

        return $plainCodes;
    }

    protected function verifyCode(UserMfaMethod $method, string $code): bool
    {
        $secret = $method->secret;

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $timestep = $this->google2fa->verifyKeyNewer($secret, $code, $method->last_used_timestep ?? 0, self::WINDOW);

        if ($timestep === false) {
            return false;
        }

        $method->forceFill([
            'last_used_at' => now(),
            'last_used_timestep' => $timestep,
        ])->save();

        return true;
    }

    protected function consumeRecoveryCode(User $user, string $code): bool
    {
        $candidates = UserMfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->get();

        $candidate = $candidates->first(
            fn (UserMfaRecoveryCode $recoveryCode): bool => Hash::check(
                mb_strtoupper(trim($code)),
                $recoveryCode->code_hash,
            ),
        );

        if ($candidate === null) {
            return false;
        }

        $candidate->forceFill(['used_at' => now()])->save();

        return true;
    }

    public function reset(User $user): void
    {
        DB::transaction(function () use ($user): void {
            UserMfaRecoveryCode::query()->where('user_id', $user->id)->delete();
            UserMfaMethod::query()->where('user_id', $user->id)->delete();
        });
    }

    protected function hashRecoveryCode(string $code): string
    {
        return Hash::make(mb_strtoupper(trim($code)));
    }
}
