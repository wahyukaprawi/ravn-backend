<?php

namespace Tests\Feature\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementAudience;

class AnnouncementAnalyticsTest extends AnnouncementTestCase
{
    public function test_ann_t09_analytics_counts_recipients_and_reads(): void
    {
        $announcement = $this->makeAnnouncement(Announcement::STATUS_PUBLISHED);

        $this->asUser($this->employee)
            ->postJson('/api/v1/announcements/'.$announcement->id.'/read')
            ->assertOk();

        $this->asUser($this->other)
            ->postJson('/api/v1/announcements/'.$announcement->id.'/read')
            ->assertOk();

        $this->asUser($this->hr)
            ->getJson('/api/v1/admin/announcements/'.$announcement->id.'/analytics')
            ->assertOk()
            ->assertJsonPath('data.recipient_count', 3)
            ->assertJsonPath('data.read_count', 2)
            ->assertJsonPath('data.read_rate', 0.6667);
    }

    public function test_ann_t09_department_audience_analytics_uses_current_assignments(): void
    {
        $announcement = $this->makeAnnouncement(Announcement::STATUS_PUBLISHED, [
            ['type' => AnnouncementAudience::TYPE_DEPARTMENT, 'id' => $this->department->id],
        ]);

        $this->asUser($this->employee)
            ->postJson('/api/v1/announcements/'.$announcement->id.'/read')
            ->assertOk();

        $this->asUser($this->hr)
            ->getJson('/api/v1/admin/announcements/'.$announcement->id.'/analytics')
            ->assertOk()
            ->assertJsonPath('data.recipient_count', 2)
            ->assertJsonPath('data.read_count', 1)
            ->assertJsonPath('data.read_rate', 0.5);
    }

    public function test_ann_t09_analytics_requires_permission(): void
    {
        $announcement = $this->makeAnnouncement(Announcement::STATUS_PUBLISHED);

        $this->asUser($this->manager)
            ->getJson('/api/v1/admin/announcements/'.$announcement->id.'/analytics')
            ->assertForbidden();

        $this->asUser($this->employee)
            ->getJson('/api/v1/admin/announcements/'.$announcement->id.'/analytics')
            ->assertForbidden();
    }
}
