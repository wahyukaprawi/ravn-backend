<?php

namespace Tests\Feature\Announcements;

use App\Models\Announcement;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\OutboxMessage;
use App\Notifications\NotificationService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class AnnouncementNotificationTest extends AnnouncementTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
    }

    public function test_ann_t04_published_announcement_creates_in_app_notifications_for_recipients_only(): void
    {
        $announcement = $this->makeAnnouncement();

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements/'.$announcement->id.'/publish')
            ->assertOk();

        $this->artisan('outbox:process', ['--once' => true])->assertSuccessful();

        $this->assertDatabaseCount('notifications', 3);
        $this->assertDatabaseCount('notification_deliveries', 3);

        foreach ([$this->manager, $this->employee, $this->other] as $recipient) {
            $this->assertDatabaseHas('notifications', [
                'type' => 'announcement.published',
                'notifiable_type' => $recipient->getMorphClass(),
                'notifiable_id' => $recipient->getKey(),
            ]);
            $this->assertDatabaseHas('notification_deliveries', [
                'channel' => NotificationDelivery::CHANNEL_IN_APP,
                'status' => NotificationDelivery::STATUS_SENT,
            ]);
        }

        $this->assertDatabaseMissing('notification_deliveries', [
            'channel' => NotificationDelivery::CHANNEL_EMAIL,
        ]);
        $this->assertDatabaseMissing('notification_deliveries', [
            'channel' => NotificationDelivery::CHANNEL_PUSH,
        ]);

        $notification = Notification::query()
            ->where('notifiable_id', $this->employee->getKey())
            ->firstOrFail();

        $this->assertSame('New announcement', $notification->data['title']);
        $this->assertSame('A new announcement has been published.', $notification->data['body']);
        $this->assertSame($announcement->id, $notification->data['context']['announcement_id']);

        $this->artisan('outbox:process', ['--once' => true])->assertSuccessful();

        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_ann_t04_notification_failure_does_not_roll_back_publication(): void
    {
        $announcement = $this->makeAnnouncement();

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements/'.$announcement->id.'/publish')
            ->assertOk();

        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('notify')->andThrow(new RuntimeException('synthetic notification failure'));
        });

        $this->artisan('outbox:process', ['--once' => true])->assertSuccessful();

        $announcement->refresh();
        $message = OutboxMessage::query()->firstOrFail();

        $this->assertSame(Announcement::STATUS_PUBLISHED, $announcement->status);
        $this->assertSame(OutboxMessage::STATUS_PENDING, $message->status);
        $this->assertNotNull($message->last_error);
        $this->assertDatabaseCount('notifications', 0);
    }
}
