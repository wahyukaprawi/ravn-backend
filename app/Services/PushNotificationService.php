<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PushNotificationService
{
    public function registerToken(User $user, string $token, string $platform, ?string $deviceId = null): DeviceToken
    {
        return DB::transaction(function () use ($user, $token, $platform, $deviceId) {
            return DeviceToken::query()->updateOrCreate(
                ['token' => $token],
                [
                    'user_id' => $user->getKey(),
                    'platform' => $platform,
                    'device_id' => $deviceId,
                    'active' => true,
                    'last_used_at' => now(),
                ],
            );
        });
    }

    public function deactivateToken(string $token): void
    {
        DeviceToken::query()->where('token', $token)->update(['active' => false]);
    }

    public function send(User $user, string $title, string $body, array $data = []): int
    {
        $count = DeviceToken::query()
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->count();

        return $count;
    }
}
