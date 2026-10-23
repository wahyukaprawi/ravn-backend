<?php

namespace App\Notifications;

use App\Jobs\DeliverNotification;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\Push\PermanentDeliveryException;
use App\Notifications\Push\PushGateway;
use App\Notifications\Push\RateLimitedException;
use App\Notifications\Push\TransientDeliveryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationDeliveryService
{
    public function __construct(private readonly PushGateway $push) {}

    public function attempt(int $deliveryId): void
    {
        $delivery = NotificationDelivery::query()
            ->with('notification.notifiable')
            ->find($deliveryId);

        if ($delivery === null || $delivery->status !== NotificationDelivery::STATUS_PENDING) {
            return;
        }

        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_PROCESSING,
            'attempts' => $delivery->attempts + 1,
        ])->save();

        try {
            $result = $this->deliver($delivery);

            if ($result === 'skipped') {
                $this->markSkipped($delivery);
            } else {
                $this->markSent($delivery);
            }
        } catch (RateLimitedException $exception) {
            $this->retryOrDeadLetter($delivery, max(0, $exception->retryAfter), $exception->errorCode);
        } catch (PermanentDeliveryException $exception) {
            $this->markFailed($delivery, $exception->errorCode);
        } catch (TransientDeliveryException $exception) {
            $this->retryOrDeadLetter($delivery, $this->backoffSeconds($delivery->attempts), $exception->errorCode);
        } catch (Throwable) {
            Log::error('notification.delivery.failed', [
                'delivery_id' => $delivery->getKey(),
                'channel' => $delivery->channel,
            ]);
            $this->retryOrDeadLetter($delivery, $this->backoffSeconds($delivery->attempts), 'delivery_error');
        }
    }

    public function retry(NotificationDelivery $delivery): bool
    {
        if (! $delivery->isRetryable()) {
            return false;
        }

        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => now(),
            'last_error' => null,
            'last_error_code' => null,
            'dead_lettered_at' => null,
        ])->save();

        DeliverNotification::dispatch($delivery->getKey());

        return true;
    }

    protected function deliver(NotificationDelivery $delivery): string
    {
        $notification = $delivery->notification;

        if (! $notification instanceof Notification) {
            return 'skipped';
        }

        $recipient = $notification->notifiable;

        if (! $recipient instanceof User || ! $recipient->isActive()) {
            return 'skipped';
        }

        return match ($delivery->channel) {
            NotificationDelivery::CHANNEL_IN_APP => 'sent',
            NotificationDelivery::CHANNEL_EMAIL => $this->sendEmail($recipient, $notification),
            NotificationDelivery::CHANNEL_PUSH => $this->sendPush($recipient, $notification),
            default => throw new PermanentDeliveryException('unknown_channel'),
        };
    }

    protected function sendEmail(User $recipient, Notification $notification): string
    {
        if (empty($recipient->email)) {
            return 'skipped';
        }

        $data = $notification->data ?? [];

        try {
            Mail::to($recipient->email)->send(new NotificationMail(
                (string) ($data['title'] ?? 'Ravn notification'),
                (string) ($data['body'] ?? 'You have a new notification.'),
            ));
        } catch (Throwable) {
            throw new TransientDeliveryException('mail_failed');
        }

        return 'sent';
    }

    protected function sendPush(User $recipient, Notification $notification): string
    {
        $tokens = $recipient->deviceTokens()->where('active', true)->get();

        if ($tokens->isEmpty()) {
            return 'skipped';
        }

        $payload = [
            'title' => 'Ravn',
            'body' => 'You have a new notification.',
            'data' => [
                'notification_id' => (string) $notification->getKey(),
                'type' => $notification->type,
            ],
        ];

        $sent = 0;
        $transient = null;
        $permanent = null;

        foreach ($tokens as $token) {
            try {
                $this->push->send($token, $payload);
                $sent++;
            } catch (PermanentDeliveryException $exception) {
                if ($exception->errorCode === 'invalid_token') {
                    $token->forceFill(['active' => false])->save();
                }

                $permanent ??= $exception;
            } catch (RateLimitedException|TransientDeliveryException $exception) {
                $transient ??= $exception;
            } catch (Throwable) {
                Log::error('notification.push.failed', [
                    'notification_id' => $notification->getKey(),
                    'device_id' => $token->device_id,
                ]);
                $transient ??= new TransientDeliveryException('delivery_error');
            }
        }

        if ($sent > 0) {
            return 'sent';
        }

        if ($transient !== null) {
            throw $transient;
        }

        throw new PermanentDeliveryException($permanent?->errorCode ?? 'push_rejected');
    }

    protected function backoffSeconds(int $attempts): int
    {
        $base = max(1, (int) config('notifications.backoff.base_seconds', 30));
        $max = max($base, (int) config('notifications.backoff.max_seconds', 300));
        $jitter = max(0, (int) config('notifications.backoff.jitter_seconds', 10));
        $delay = min($max, $base * (2 ** max(0, $attempts - 1)));

        return $delay + ($jitter > 0 ? random_int(0, $jitter) : 0);
    }

    protected function retryOrDeadLetter(NotificationDelivery $delivery, int $delaySeconds, string $errorCode): void
    {
        if ($delivery->attempts >= max(1, (int) config('notifications.max_attempts', 8))) {
            $delivery->forceFill([
                'status' => NotificationDelivery::STATUS_DEAD_LETTER,
                'last_error_code' => $errorCode,
                'last_error' => $this->summary($errorCode),
                'dead_lettered_at' => now(),
            ])->save();

            return;
        }

        $availableAt = now()->addSeconds(max(0, $delaySeconds));

        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_PENDING,
            'available_at' => $availableAt,
            'last_error_code' => $errorCode,
            'last_error' => $this->summary($errorCode),
        ])->save();

        DeliverNotification::dispatch($delivery->getKey())->delay($availableAt);
    }

    protected function markSent(NotificationDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_SENT,
            'sent_at' => now(),
            'last_error' => null,
            'last_error_code' => null,
        ])->save();
    }

    protected function markSkipped(NotificationDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_SKIPPED,
            'last_error' => null,
            'last_error_code' => null,
        ])->save();
    }

    protected function markFailed(NotificationDelivery $delivery, string $errorCode): void
    {
        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_FAILED,
            'last_error_code' => $errorCode,
            'last_error' => $this->summary($errorCode),
        ])->save();
    }

    protected function summary(string $errorCode): string
    {
        return match ($errorCode) {
            'rate_limited' => 'The provider asked us to slow down.',
            'provider_unavailable' => 'The provider is temporarily unavailable.',
            'provider_auth' => 'The provider credentials could not be used.',
            'provider_rejected' => 'The provider rejected the message.',
            'invalid_token' => 'The device token is no longer valid.',
            'mail_failed' => 'The email could not be sent.',
            'push_not_configured' => 'Push is not configured.',
            'unknown_channel' => 'The delivery channel is not supported.',
            'worker_recovered' => 'The worker stopped before finishing the delivery.',
            default => 'The delivery failed.',
        };
    }
}
