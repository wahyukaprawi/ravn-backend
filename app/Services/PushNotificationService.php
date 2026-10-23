<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PushNotificationService
{
    public function registerToken(User $user, string $token, string $platform, ?string $deviceId = null): DeviceToken
    {
        return DB::transaction(function () use ($user, $token, $platform, $deviceId): DeviceToken {
            if ($deviceId !== null && $deviceId !== '') {
                DeviceToken::query()
                    ->where('user_id', $user->getKey())
                    ->where('device_id', $deviceId)
                    ->where('token', '!=', $token)
                    ->update(['active' => false]);
            }

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

    public function revoke(DeviceToken $device): void
    {
        $device->forceFill(['active' => false])->save();
    }
}
