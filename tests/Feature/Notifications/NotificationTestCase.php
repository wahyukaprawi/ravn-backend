<?php

namespace Tests\Feature\Notifications;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Notifications\Push\PushGateway;
use App\Services\Outbox;
use App\Services\PushNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePushGateway;
use Tests\TestCase;

abstract class NotificationTestCase extends TestCase
{
    protected User $employee;

    protected User $manager;

    protected User $other;

    protected User $superAdmin;

    protected FakePushGateway $push;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

        $this->manager = User::factory()->create()->assignRole('manager');
        $this->employee = User::factory()->create([
            'manager_id' => $this->manager->getKey(),
        ])->assignRole('employee');
        $this->other = User::factory()->create()->assignRole('employee');
        $this->superAdmin = User::factory()->create()->assignRole('super_admin');

        $this->push = new FakePushGateway;
        $this->app->instance(PushGateway::class, $this->push);

        Mail::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function tokenFor(User $user, bool $freshMfa = false): string
    {
        $token = $user->createToken('phpunit');

        if ($freshMfa) {
            $token->accessToken->forceFill(['mfa_verified_at' => now()])->save();
        }

        return $token->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function outbox(string $event, array $payload, int $aggregateId = 1): OutboxMessage
    {
        return app(Outbox::class)->dispatch($event, $payload, 'test_subject', $aggregateId);
    }

    protected function processOutbox(): void
    {
        $this->artisan('outbox:process', ['--once' => true])->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function makeNotification(User $user, string $type = 'test.notification', array $data = []): Notification
    {
        return Notification::query()->create([
            'type' => $type,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => array_merge([
                'title' => 'Test notification',
                'body' => 'Test body',
                'context' => [],
            ], $data),
        ]);
    }

    protected function makeDelivery(
        Notification $notification,
        string $channel = NotificationDelivery::CHANNEL_PUSH,
        string $status = NotificationDelivery::STATUS_PENDING,
        int $attempts = 0,
    ): NotificationDelivery {
        return $notification->deliveries()->create([
            'channel' => $channel,
            'status' => $status,
            'attempts' => $attempts,
            'available_at' => now(),
            'last_error_code' => null,
            'last_error' => null,
        ]);
    }

    protected function registerDevice(
        User $user,
        string $token = 'device-token',
        string $deviceId = 'device-1',
        string $platform = 'android',
    ): DeviceToken {
        return app(PushNotificationService::class)->registerToken($user, $token, $platform, $deviceId);
    }
}
