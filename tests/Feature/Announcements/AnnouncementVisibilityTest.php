<?php

namespace Tests\Feature\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementAudience;
use App\Models\AnnouncementRead;

class AnnouncementVisibilityTest extends AnnouncementTestCase
{
    public function test_ann_t01_audience_targeting_limits_employee_visibility(): void
    {
        $all = $this->makeAnnouncement(Announcement::STATUS_PUBLISHED);
        $engineering = $this->makeAnnouncement(Announcement::STATUS_PUBLISHED, [
            ['type' => AnnouncementAudience::TYPE_DEPARTMENT, 'id' => $this->department->id],
        ], ['title' => 'Engineering only']);
        $branch = $this->makeAnnouncement(Announcement::STATUS_PUBLISHED, [
            ['type' => AnnouncementAudience::TYPE_WORK_LOCATION, 'id' => $this->otherLocation->id],
        ], ['title' => 'Branch only']);
        $specific = $this->makeAnnouncement(Announcement::STATUS_PUBLISHED, [
            ['type' => AnnouncementAudience::TYPE_SPECIFIC_EMPLOYEE, 'id' => $this->employeeProfile->id],
        ], ['title' => 'Specific employee']);

        $employeeTitles = $this->asUser($this->employee)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->json('data.*.title');

        $this->assertEqualsCanonicalizing(
            [$all->title, $engineering->title, $specific->title],
            $employeeTitles,
        );

        $otherTitles = $this->asUser($this->other)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->json('data.*.title');

        $this->assertEqualsCanonicalizing([$all->title, $branch->title], $otherTitles);

        $this->asUser($this->employee)
            ->getJson('/api/v1/announcements/'.$branch->id)
            ->assertNotFound();

        $this->asUser($this->other)
            ->getJson('/api/v1/announcements/'.$engineering->id)
            ->assertNotFound();

        $this->asUser($this->employee)
            ->getJson('/api/v1/announcements/'.$specific->id)
            ->assertOk()
            ->assertJsonPath('data.body', 'The employee handbook has been updated.');
    }

    public function test_ann_t01_archived_announcements_are_not_visible_to_employees(): void
    {
        $announcement = $this->makeAnnouncement(Announcement::STATUS_ARCHIVED);

        $this->asUser($this->employee)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->asUser($this->employee)
            ->getJson('/api/v1/announcements/'.$announcement->id)
            ->assertNotFound();
    }

    public function test_ann_t05_read_state_is_unique_per_employee_and_unread_filter_works(): void
    {
        $announcement = $this->makeAnnouncement(Announcement::STATUS_PUBLISHED);

        $this->asUser($this->employee)
            ->getJson('/api/v1/announcements?unread=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.read_at', null);

        $this->asUser($this->employee)
            ->postJson('/api/v1/announcements/'.$announcement->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $announcement->id);

        $this->asUser($this->employee)
            ->postJson('/api/v1/announcements/'.$announcement->id.'/read')
            ->assertOk();

        $this->assertDatabaseCount('announcement_reads', 1);
        $this->assertDatabaseHas('announcement_reads', [
            'announcement_id' => $announcement->id,
            'employee_id' => $this->employeeProfile->id,
            'user_id' => $this->employee->id,
        ]);
        $this->assertNotNull(AnnouncementRead::query()->first()?->read_at);

        $this->asUser($this->employee)
            ->getJson('/api/v1/announcements?unread=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->asUser($this->employee)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.read_at', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_employees_without_profiles_do_not_receive_employee_announcements(): void
    {
        $this->makeAnnouncement(Announcement::STATUS_PUBLISHED);

        $this->asUser($this->hr)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }
}
