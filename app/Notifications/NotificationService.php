<?php

namespace App\Notifications;

use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function notify(OutboxMessage $message, User $recipient, string $type, string $title, string $body, array $context = []): ?Notification
    {
        if (! $recipient->isActive()) {
            return null;
        }

        try {
            [$notification, $deliveryIds] = DB::transaction(function () use ($message, $recipient, $type, $title, $body, $context): array {
                $notification = Notification::query()->firstOrCreate(
                    [
                        'outbox_message_id' => $message->getKey(),
                        'notifiable_id' => $recipient->getKey(),
                    ],
                    [
                        'notifiable_type' => $recipient->getMorphClass(),
                        'type' => $type,
                        'data' => [
                            'title' => $title,
                            'body' => $body,
                            'context' => $context,
                        ],
                    ],
                );

                if (! $notification->wasRecentlyCreated) {
                    return [$notification, []];
                }

                $notification->deliveries()->create([
                    'channel' => NotificationDelivery::CHANNEL_IN_APP,
                    'status' => NotificationDelivery::STATUS_SENT,
                    'attempts' => 1,
                    'available_at' => now(),
                    'sent_at' => now(),
                ]);

                $deliveryIds = [];

                if (! empty($recipient->email)) {
                    $deliveryIds[] = $notification->deliveries()->create([
                        'channel' => NotificationDelivery::CHANNEL_EMAIL,
                        'status' => NotificationDelivery::STATUS_PENDING,
                        'attempts' => 0,
                        'available_at' => now(),
                    ])->getKey();
                }

                if ($recipient->deviceTokens()->where('active', true)->exists()) {
                    $deliveryIds[] = $notification->deliveries()->create([
                        'channel' => NotificationDelivery::CHANNEL_PUSH,
                        'status' => NotificationDelivery::STATUS_PENDING,
                        'attempts' => 0,
                        'available_at' => now(),
                    ])->getKey();
                }

                return [$notification, $deliveryIds];
            });
        } catch (UniqueConstraintViolationException) {
            return Notification::query()
                ->where('outbox_message_id', $message->getKey())
                ->where('notifiable_id', $recipient->getKey())
                ->first();
        }

        foreach ($deliveryIds as $deliveryId) {
            DeliverNotification::dispatch($deliveryId);
        }

        return $notification;
    }
}
