<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;

class NotificationApiTest extends NotificationTestCase
{
    public function test_notifications_are_scoped_to_the_authenticated_user(): void
    {
        $mine = $this->makeNotification($this->employee, 'leave.approved', ['title' => 'Mine']);
        $this->makeNotification($this->other, 'leave.approved', ['title' => 'Theirs']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->employee))
            ->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->getKey())
            ->assertJsonPath('data.0.type', 'leave.approved')
            ->assertJsonPath('data.0.title', 'Mine')
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonStructure([
                'data' => [[
                    'id', 'type', 'title', 'body', 'context', 'read_at', 'created_at',
                ]],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);
    }

    public function test_unread_filter_returns_only_unread_notifications(): void
    {
        $unread = $this->makeNotification($this->employee);
        $read = $this->makeNotification($this->employee);
        $read->forceFill(['read_at' => now()])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->employee))
            ->getJson('/api/v1/notifications?unread=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unread->getKey());
    }

    public function test_a_user_cannot_view_another_users_notification(): void
    {
        $notification = $this->makeNotification($this->other);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->employee))
            ->getJson('/api/v1/notifications/'.$notification->getKey())
            ->assertNotFound();
    }

    public function test_marking_a_notification_read_is_idempotent(): void
    {
        $notification = $this->makeNotification($this->employee);

        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($this->employee)];

        $this->withHeaders($headers)
            ->postJson('/api/v1/notifications/'.$notification->getKey().'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $notification->getKey());

        $this->assertNotNull($notification->fresh()->read_at);

        $firstReadAt = $notification->fresh()->read_at;

        $this->withHeaders($headers)
            ->postJson('/api/v1/notifications/'.$notification->getKey().'/read')
            ->assertOk();

        $this->assertTrue($firstReadAt->equalTo($notification->fresh()->read_at));
    }

    public function test_mark_all_read_updates_only_the_current_users_unread_notifications(): void
    {
        $this->makeNotification($this->employee);
        $this->makeNotification($this->employee);
        $other = $this->makeNotification($this->other);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->employee))
            ->postJson('/api/v1/notifications/read-all');

        $response->assertOk()->assertJsonPath('updated', 2);

        $this->assertSame(0, Notification::query()
            ->where('notifiable_id', $this->employee->getKey())
            ->whereNull('read_at')
            ->count());
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_notification_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
    }
}
