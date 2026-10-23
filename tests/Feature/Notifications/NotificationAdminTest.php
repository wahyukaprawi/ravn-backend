<?php

namespace Tests\Feature\Notifications;

use App\Jobs\DeliverNotification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

class NotificationAdminTest extends NotificationTestCase
{
    public function test_failure_inspection_requires_an_explicit_permission(): void
    {
        $delivery = $this->failedDelivery();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->employee))
            ->getJson('/api/v1/admin/notification-deliveries')
            ->assertForbidden();

        $this->freshRequest();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->superAdmin))
            ->getJson('/api/v1/admin/notification-deliveries')
            ->assertForbidden();

        $this->freshRequest();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('notifications.view_failures');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($viewer))
            ->getJson('/api/v1/admin/notification-deliveries?status=failed');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $delivery->getKey())
            ->assertJsonPath('data.0.status', NotificationDelivery::STATUS_FAILED)
            ->assertJsonPath('data.0.last_error_code', 'provider_rejected')
            ->assertJsonStructure([
                'data' => [[
                    'id', 'notification_id', 'type', 'channel', 'status', 'attempts',
                    'last_error_code', 'last_error', 'sent_at', 'dead_lettered_at', 'created_at',
                ]],
            ]);

        $this->assertStringNotContainsString(
            'device-token',
            (string) json_encode($response->json()),
        );
    }

    public function test_operational_notification_permissions_are_not_implied_by_the_super_admin_role(): void
    {
        $superAdmin = $this->superAdmin->fresh();

        $this->assertTrue($superAdmin->hasRole('super_admin'));
        $this->assertFalse($superAdmin->hasPermissionTo('notifications.view_failures'));
        $this->assertFalse($superAdmin->hasPermissionTo('notifications.retry'));

        $superAdmin->givePermissionTo('notifications.view_failures');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($superAdmin))
            ->getJson('/api/v1/admin/notification-deliveries')
            ->assertOk();
    }

    public function test_manual_retry_requires_permission_and_fresh_mfa(): void
    {
        $delivery = $this->failedDelivery();

        $retryer = User::factory()->create();
        $retryer->givePermissionTo('notifications.retry');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($retryer))
            ->postJson('/api/v1/admin/notification-deliveries/'.$delivery->getKey().'/retry')
            ->assertForbidden();

        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->fresh()->status);

        $this->freshRequest();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($retryer, freshMfa: true))
            ->postJson('/api/v1/admin/notification-deliveries/'.$delivery->getKey().'/retry');

        $response->assertOk()
            ->assertJsonPath('data.status', NotificationDelivery::STATUS_PENDING)
            ->assertJsonPath('data.attempts', 0);

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $delivery->status);
        $this->assertNull($delivery->last_error_code);
        $this->assertNull($delivery->dead_lettered_at);

        Queue::assertPushed(DeliverNotification::class, 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notification.delivery.retried']);
    }

    public function test_manual_retry_requires_the_explicit_retry_permission(): void
    {
        $delivery = $this->failedDelivery();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->employee, freshMfa: true))
            ->postJson('/api/v1/admin/notification-deliveries/'.$delivery->getKey().'/retry')
            ->assertForbidden();
    }

    public function test_only_failed_or_dead_letter_deliveries_can_be_retried(): void
    {
        $notification = $this->makeNotification($this->employee);
        $delivery = $this->makeDelivery($notification, NotificationDelivery::CHANNEL_EMAIL, NotificationDelivery::STATUS_SENT);

        $retryer = User::factory()->create();
        $retryer->givePermissionTo('notifications.retry');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($retryer, freshMfa: true))
            ->postJson('/api/v1/admin/notification-deliveries/'.$delivery->getKey().'/retry')
            ->assertStatus(409);

        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->fresh()->status);
    }

    protected function failedDelivery(): NotificationDelivery
    {
        $notification = $this->makeNotification($this->employee);
        $delivery = $this->makeDelivery(
            $notification,
            NotificationDelivery::CHANNEL_PUSH,
            NotificationDelivery::STATUS_FAILED,
            3,
        );

        $delivery->forceFill([
            'last_error_code' => 'provider_rejected',
            'last_error' => 'The provider rejected the message.',
        ])->save();

        return $delivery;
    }
}
