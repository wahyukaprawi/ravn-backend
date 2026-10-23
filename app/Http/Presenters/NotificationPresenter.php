<?php

namespace App\Http\Presenters;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\NotificationDelivery;

class NotificationPresenter
{
    public static function notification(Notification $notification): array
    {
        $data = $notification->data ?? [];

        return [
            'id' => $notification->getKey(),
            'type' => $notification->type,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'context' => $data['context'] ?? [],
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    public static function delivery(NotificationDelivery $delivery): array
    {
        return [
            'id' => $delivery->getKey(),
            'notification_id' => $delivery->notification_id,
            'type' => $delivery->notification?->type,
            'channel' => $delivery->channel,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'last_error_code' => $delivery->last_error_code,
            'last_error' => $delivery->last_error,
            'sent_at' => $delivery->sent_at?->toIso8601String(),
            'dead_lettered_at' => $delivery->dead_lettered_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
        ];
    }

    public static function device(DeviceToken $device): array
    {
        return [
            'id' => $device->getKey(),
            'platform' => $device->platform,
            'device_id' => $device->device_id,
            'active' => $device->active,
            'last_used_at' => $device->last_used_at?->toIso8601String(),
        ];
    }
}
