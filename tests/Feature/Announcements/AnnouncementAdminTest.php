<?php

namespace Tests\Feature\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementAudience;
use App\Models\OutboxMessage;
use App\Models\WorkLocation;

class AnnouncementAdminTest extends AnnouncementTestCase
{
    public function test_ann_t02_hr_can_create_a_draft_with_all_employee_audience(): void
    {
        $response = $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', Announcement::STATUS_DRAFT)
            ->assertJsonPath('data.title', 'Policy update')
            ->assertJsonPath('data.audiences.0.type', AnnouncementAudience::TYPE_ALL_EMPLOYEES)
            ->assertJsonPath('data.audiences.0.id', null);

        $id = $response->json('data.id');

        $this->assertDatabaseHas('announcement_audiences', [
            'announcement_id' => $id,
            'audience_type' => AnnouncementAudience::TYPE_ALL_EMPLOYEES,
            'audience_id' => 0,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'announcement.created',
            'subject_id' => $id,
        ]);
    }

    public function test_ann_t02_invalid_audience_targets_and_past_schedules_are_rejected(): void
    {
        $inactiveLocation = WorkLocation::query()->create([
            'code' => 'OLD',
            'name' => 'Old Office',
            'address' => '3 Example Street',
            'latitude' => -6.4,
            'longitude' => 107.0,
            'radius_meters' => 100,
            'timezone' => 'Asia/Jakarta',
            'active' => false,
        ]);

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements', $this->payload([
                ['type' => AnnouncementAudience::TYPE_DEPARTMENT, 'id' => 999999],
            ]))
            ->assertStatus(422);

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements', $this->payload([
                ['type' => AnnouncementAudience::TYPE_WORK_LOCATION, 'id' => $inactiveLocation->id],
            ]))
            ->assertStatus(422);

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements', $this->payload([
                ['type' => AnnouncementAudience::TYPE_SPECIFIC_EMPLOYEE, 'id' => 999999],
            ]))
            ->assertStatus(422);

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements', $this->payload([
                ['type' => AnnouncementAudience::TYPE_DEPARTMENT, 'id' => null],
            ]))
            ->assertStatus(422);

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements', $this->payload([
                ['type' => 'role', 'id' => 1],
            ]))
            ->assertStatus(422);

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements', $this->payload([], [
                'scheduled_at' => '2026-06-14 09:00:00',
            ]))
            ->assertStatus(422);
    }

    public function test_ann_t01_drafts_are_hidden_from_employees_but_visible_to_admins(): void
    {
        $announcement = $this->makeAnnouncement();

        $this->asUser($this->employee)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->asUser($this->employee)
            ->getJson('/api/v1/announcements/'.$announcement->id)
            ->assertNotFound();

        $this->asUser($this->hr)
            ->getJson('/api/v1/admin/announcements')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $announcement->id)
            ->assertJsonPath('data.0.status', Announcement::STATUS_DRAFT);
    }

    public function test_ann_t04_publish_is_atomic_and_idempotent(): void
    {
        $announcement = $this->makeAnnouncement();

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements/'.$announcement->id.'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', Announcement::STATUS_PUBLISHED);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'announcement.published',
            'subject_id' => $announcement->id,
        ]);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseHas('outbox_messages', [
            'event_type' => 'announcement.published',
            'aggregate_id' => $announcement->id,
            'status' => OutboxMessage::STATUS_PENDING,
        ]);

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements/'.$announcement->id.'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', Announcement::STATUS_PUBLISHED);

        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertNotNull(Announcement::query()->findOrFail($announcement->id)->published_at);
    }

    public function test_ann_t08_admin_permissions_are_required(): void
    {
        $this->asUser($this->employee)
            ->postJson('/api/v1/admin/announcements', $this->payload())
            ->assertForbidden();

        $this->asUser($this->manager)
            ->getJson('/api/v1/admin/announcements')
            ->assertForbidden();

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements', $this->payload())
            ->assertCreated();
    }

    public function test_ann_t02_draft_can_be_edited_and_audiences_are_replaced(): void
    {
        $announcement = $this->makeAnnouncement();

        $this->asUser($this->hr)
            ->patchJson('/api/v1/admin/announcements/'.$announcement->id, $this->payload([
                ['type' => AnnouncementAudience::TYPE_DEPARTMENT, 'id' => $this->department->id],
            ], ['title' => 'Updated policy']))
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated policy')
            ->assertJsonPath('data.audiences.0.type', AnnouncementAudience::TYPE_DEPARTMENT)
            ->assertJsonPath('data.audiences.0.id', $this->department->id);

        $this->assertDatabaseCount('announcement_audiences', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'announcement.updated',
            'subject_id' => $announcement->id,
        ]);
    }

    public function test_ann_t06_published_announcements_cannot_be_edited_and_only_published_can_be_archived(): void
    {
        $draft = $this->makeAnnouncement();

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements/'.$draft->id.'/archive')
            ->assertStatus(409);

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements/'.$draft->id.'/publish')
            ->assertOk();

        $this->asUser($this->hr)
            ->patchJson('/api/v1/admin/announcements/'.$draft->id, $this->payload())
            ->assertStatus(409);

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/announcements/'.$draft->id.'/archive')
            ->assertOk()
            ->assertJsonPath('data.status', Announcement::STATUS_ARCHIVED);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'announcement.archived',
            'subject_id' => $draft->id,
        ]);

        $this->asUser($this->employee)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }
}
