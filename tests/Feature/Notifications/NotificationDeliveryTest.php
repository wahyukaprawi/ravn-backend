<?php

namespace Tests\Feature\Notifications;

use App\Jobs\DeliverNotification;
use App\Mail\NotificationMail;
use App\Models\NotificationDelivery;
use App\Notifications\NotificationDeliveryService;
use App\Notifications\Push\PermanentDeliveryException;
use App\Notifications\Push\RateLimitedException;
use App\Notifications\Push\TransientDeliveryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class NotificationDeliveryTest extends NotificationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notifications.max_attempts' => 8,
            'notifications.backoff.base_seconds' => 30,
            'notifications.backoff.max_seconds' => 300,
            'notifications.backoff.jitter_seconds' => 0,
        ]);
    }

    public function test_nt_t03_transient_failures_retry_with_progressive_backoff(): void
    {
        $this->push->failure = new TransientDeliveryException('provider_unavailable');
        $delivery = $this->pushDelivery();
        $service = app(NotificationDeliveryService::class);

        $service->attempt($delivery->getKey());

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('provider_unavailable', $delivery->last_error_code);
        $this->assertSame(now()->addSeconds(30)->timestamp, $delivery->available_at->timestamp);

        $service->attempt($delivery->getKey());

        $delivery->refresh();
        $this->assertSame(2, $delivery->attempts);
        $this->assertSame(now()->addSeconds(60)->timestamp, $delivery->available_at->timestamp);

        Queue::assertPushed(DeliverNotification::class, 2);
    }

    public function test_nt_t03_jitter_is_added_to_the_backoff(): void
    {
        config(['notifications.backoff.jitter_seconds' => 10]);

        $this->push->failure = new TransientDeliveryException('provider_unavailable');
        $delivery = $this->pushDelivery();

        app(NotificationDeliveryService::class)->attempt($delivery->getKey());

        $delivery->refresh();
        $this->assertTrue($delivery->available_at->between(
            now()->copy()->addSeconds(30),
            now()->copy()->addSeconds(40),
        ));
    }

    public function test_nt_t04_rate_limit_uses_retry_after(): void
    {
        $this->push->failure = new RateLimitedException(120);
        $delivery = $this->pushDelivery();

        app(NotificationDeliveryService::class)->attempt($delivery->getKey());

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $delivery->status);
        $this->assertSame('rate_limited', $delivery->last_error_code);
        $this->assertSame(now()->addSeconds(120)->timestamp, $delivery->available_at->timestamp);
    }

    public function test_nt_t05_permanent_failure_stops_retrying(): void
    {
        $this->push->failure = new PermanentDeliveryException('provider_rejected');
        $delivery = $this->pushDelivery();

        app(NotificationDeliveryService::class)->attempt($delivery->getKey());

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('provider_rejected', $delivery->last_error_code);
        $this->assertNull($delivery->dead_lettered_at);
        Queue::assertNotPushed(DeliverNotification::class);
    }

    public function test_nt_t06_exhausted_delivery_becomes_dead_letter(): void
    {
        config(['notifications.max_attempts' => 2]);

        $this->push->failure = new TransientDeliveryException('provider_unavailable');
        $delivery = $this->pushDelivery();
        $service = app(NotificationDeliveryService::class);

        $service->attempt($delivery->getKey());
        $service->attempt($delivery->getKey());

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_DEAD_LETTER, $delivery->status);
        $this->assertSame(2, $delivery->attempts);
        $this->assertSame('provider_unavailable', $delivery->last_error_code);
        $this->assertNotNull($delivery->dead_lettered_at);
        Queue::assertPushed(DeliverNotification::class, 1);
    }

    public function test_nt_t08_unexpected_errors_store_only_a_safe_summary(): void
    {
        $this->push->failure = new RuntimeException('secret-token-abc must never be stored');
        $delivery = $this->pushDelivery();

        app(NotificationDeliveryService::class)->attempt($delivery->getKey());

        $delivery->refresh();
        $this->assertSame('delivery_error', $delivery->last_error_code);
        $this->assertSame('The delivery failed.', $delivery->last_error);
        $this->assertStringNotContainsString('secret-token', (string) $delivery->last_error);
        $this->assertStringNotContainsString('secret-token', (string) json_encode($delivery->getAttributes()));
    }

    public function test_nt_t09_stuck_processing_deliveries_are_recovered(): void
    {
        $delivery = $this->pushDelivery();
        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_PROCESSING,
            'attempts' => 1,
        ])->save();

        DB::table('notification_deliveries')
            ->where('id', $delivery->getKey())
            ->update(['updated_at' => now()->subHour()]);

        $this->artisan('notifications:recover-deliveries', ['--minutes' => 5])->assertSuccessful();

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $delivery->status);
        $this->assertSame('worker_recovered', $delivery->last_error_code);
        Queue::assertPushed(DeliverNotification::class, 1);
    }

    public function test_nt_t10_invalid_device_tokens_are_deactivated(): void
    {
        $device = $this->registerDevice($this->employee);
        $delivery = $this->pushDelivery(registerDevice: false);

        $this->push->failure = new PermanentDeliveryException('invalid_token');

        app(NotificationDeliveryService::class)->attempt($delivery->getKey());

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('invalid_token', $delivery->last_error_code);
        $this->assertFalse($device->fresh()->active);
    }

    public function test_nt_t11_push_payload_is_minimal(): void
    {
        $delivery = $this->pushDelivery();

        app(NotificationDeliveryService::class)->attempt($delivery->getKey());

        $this->assertCount(1, $this->push->sent);
        $payload = $this->push->sent[0]['payload'];

        $this->assertSame('Ravn', $payload['title']);
        $this->assertSame('You have a new notification.', $payload['body']);
        $this->assertSame(['notification_id', 'type'], array_keys($payload['data']));
        $this->assertSame((string) $delivery->notification_id, $payload['data']['notification_id']);
        $this->assertSame('test.notification', $payload['data']['type']);
        $this->assertArrayNotHasKey('context', $payload);
    }

    public function test_nt_t12_in_app_and_email_survive_a_push_failure(): void
    {
        $this->push->failure = new TransientDeliveryException('provider_unavailable');
        $this->registerDevice($this->employee);

        $notification = $this->makeNotification($this->employee);
        $inApp = $this->makeDelivery($notification, NotificationDelivery::CHANNEL_IN_APP, NotificationDelivery::STATUS_SENT);
        $email = $this->makeDelivery($notification, NotificationDelivery::CHANNEL_EMAIL);
        $push = $this->makeDelivery($notification, NotificationDelivery::CHANNEL_PUSH);

        $service = app(NotificationDeliveryService::class);
        $service->attempt($email->getKey());
        $service->attempt($push->getKey());

        $this->assertSame(NotificationDelivery::STATUS_SENT, $inApp->fresh()->status);
        $this->assertSame(NotificationDelivery::STATUS_SENT, $email->fresh()->status);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $push->fresh()->status);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($this->employee->email));
    }

    public function test_push_delivery_is_skipped_without_active_tokens(): void
    {
        $notification = $this->makeNotification($this->employee);
        $delivery = $this->makeDelivery($notification);

        app(NotificationDeliveryService::class)->attempt($delivery->getKey());

        $this->assertSame(NotificationDelivery::STATUS_SKIPPED, $delivery->fresh()->status);
        $this->assertSame(0, $this->push->calls);
    }

    public function test_email_delivery_succeeds_through_the_configured_mailer(): void
    {
        $notification = $this->makeNotification($this->employee);
        $delivery = $this->makeDelivery($notification, NotificationDelivery::CHANNEL_EMAIL);

        app(NotificationDeliveryService::class)->attempt($delivery->getKey());

        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->sent_at);
        Mail::assertSent(NotificationMail::class, 1);
    }

    protected function pushDelivery(bool $registerDevice = true): NotificationDelivery
    {
        if ($registerDevice) {
            $this->registerDevice($this->employee);
        }

        $notification = $this->makeNotification($this->employee);

        return $this->makeDelivery($notification);
    }
}
