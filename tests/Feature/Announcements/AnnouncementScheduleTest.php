<?php

namespace Tests\Feature\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementAudience;
use App\Models\OutboxMessage;
use Illuminate\Support\Carbon;

class AnnouncementScheduleTest extends AnnouncementTestCase
{
    public function test_ann_t03_scheduled_announcement_is_published_only_when_due_and_is_idempotent(): void
    {
        $announcement = $this->makeAnnouncement(Announcement::STATUS_SCHEDULED, [
            ['type' => AnnouncementAudience::TYPE_ALL_EMPLOYEES, 'id' => null],
        ], [
            'scheduled_at' => now()->addHour(),
        ]);

        $this->asUser($this->employee)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->artisan('announcements:publish-scheduled')->assertSuccessful();

        $this->assertSame(Announcement::STATUS_SCHEDULED, $announcement->fresh()->status);
        $this->assertDatabaseCount('outbox_messages', 0);

        Carbon::setTestNow(Carbon::parse('2026-06-15 10:01:00'));

        $this->artisan('announcements:publish-scheduled')
            ->expectsOutput('Published 1 scheduled announcements.')
            ->assertSuccessful();

        $announcement->refresh();

        $this->assertSame(Announcement::STATUS_PUBLISHED, $announcement->status);
        $this->assertNotNull($announcement->published_at);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseHas('outbox_messages', [
            'event_type' => 'announcement.published',
            'aggregate_id' => $announcement->id,
            'status' => OutboxMessage::STATUS_PENDING,
        ]);

        $this->artisan('announcements:publish-scheduled')
            ->expectsOutput('Published 0 scheduled announcements.')
            ->assertSuccessful();

        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_ann_t03_creating_with_a_scheduled_time_sets_scheduled_status(): void
    {
        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements', $this->payload([
                ['type' => AnnouncementAudience::TYPE_ALL_EMPLOYEES, 'id' => null],
            ], [
                'scheduled_at' => '2026-06-15 11:00:00',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.status', Announcement::STATUS_SCHEDULED)
            ->assertJsonPath('data.scheduled_at', fn ($value) => is_string($value) && $value !== '');
    }
}
