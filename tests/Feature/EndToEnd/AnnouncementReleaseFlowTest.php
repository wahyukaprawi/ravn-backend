<?php

namespace Tests\Feature\EndToEnd;

use App\Models\Announcement;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Announcements\AnnouncementTestCase;

class AnnouncementReleaseFlowTest extends AnnouncementTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
    }

    public function test_e2e_07_announcement_lifecycle_read_state_analytics_and_notification(): void
    {
        $created = $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', Announcement::STATUS_DRAFT);

        $announcementId = $created->json('data.id');

        $this->asUser($this->employee)->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->asUser($this->hr)
            ->postJson("/api/v1/admin/announcements/{$announcementId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', Announcement::STATUS_PUBLISHED);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'announcement.published',
            'subject_id' => $announcementId,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'event_type' => 'announcement.published',
            'aggregate_id' => $announcementId,
            'status' => OutboxMessage::STATUS_PENDING,
        ]);
        $this->assertDatabaseCount('notifications', 0);

        $this->artisan('outbox:process', ['--once' => true])->assertSuccessful();

        $this->assertDatabaseCount('notifications', 3);
        $this->assertDatabaseCount('notification_deliveries', 3);
        $this->assertDatabaseMissing('notification_deliveries', [
            'channel' => NotificationDelivery::CHANNEL_EMAIL,
        ]);
        $this->assertDatabaseMissing('notification_deliveries', [
            'channel' => NotificationDelivery::CHANNEL_PUSH,
        ]);

        $notification = Notification::query()
            ->where('notifiable_id', $this->employee->getKey())
            ->where('type', 'announcement.published')
            ->firstOrFail();

        $this->assertSame($announcementId, $notification->data['context']['announcement_id']);

        $this->asUser($this->employee)->getJson('/api/v1/announcements?unread=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $announcementId);

        $this->asUser($this->employee)->getJson('/api/v1/announcements/'.$announcementId)
            ->assertOk()
            ->assertJsonPath('data.title', 'Policy update')
            ->assertJsonPath('data.body', 'The employee handbook has been updated.');

        $read = $this->asUser($this->employee)
            ->postJson('/api/v1/announcements/'.$announcementId.'/read')
            ->assertOk();

        $this->assertNotNull($read->json('data.read_at'));

        $this->asUser($this->employee)->getJson('/api/v1/announcements?unread=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->asUser($this->hr)
            ->getJson('/api/v1/admin/announcements/'.$announcementId.'/analytics')
            ->assertOk()
            ->assertJsonPath('data.recipient_count', 3)
            ->assertJsonPath('data.read_count', 1);

        $this->asUser($this->employee)->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->asUser($this->employee)
            ->postJson('/api/v1/notifications/'.$notification->getKey().'/read')
            ->assertOk();

        $this->asUser($this->employee)->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->asUser($this->hr)
            ->postJson("/api/v1/admin/announcements/{$announcementId}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', Announcement::STATUS_ARCHIVED);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'announcement.archived',
            'subject_id' => $announcementId,
        ]);

        $this->asUser($this->employee)->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->asUser($this->employee)->getJson('/api/v1/announcements/'.$announcementId)
            ->assertNotFound();
    }
}
