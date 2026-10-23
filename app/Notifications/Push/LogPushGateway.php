<?php

namespace App\Notifications\Push;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;

class LogPushGateway implements PushGateway
{
    public function send(DeviceToken $device, array $payload): void
    {
        Log::info('push.dispatched', [
            'device_id' => $device->device_id,
            'platform' => $device->platform,
            'notification_id' => $payload['data']['notification_id'] ?? null,
        ]);
    }
}
