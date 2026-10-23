<?php

namespace Tests\Feature\EndToEnd;

use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Leave\LeaveTestCase;

class LeaveReleaseFlowTest extends LeaveTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
    }

    public function test_e2e_03_e2e_05_leave_approval_outbox_and_notification_read(): void
    {
        [$employee, $manager, $type] = $this->scenario(10);
        $hr = $this->userWithRole('hr_admin');

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02', [
            'reason' => 'Release acceptance leave request.',
        ])->assertCreated()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING)
            ->assertJsonPath('data.workflow.current_step.key', 'manager')
            ->json('data.id');

        $this->assertDatabaseHas('outbox_messages', [
            'event_type' => 'leave.request.submitted',
            'aggregate_id' => $requestId,
            'status' => OutboxMessage::STATUS_PENDING,
        ]);
        $this->assertDatabaseCount('notifications', 0);

        $this->artisan('outbox:process', ['--once' => true])->assertSuccessful();

        $managerNotification = Notification::query()
            ->where('notifiable_id', $manager->getKey())
            ->where('type', 'leave.approval_required')
            ->firstOrFail();

        $this->assertSame('Leave request needs review', $managerNotification->data['title']);

        $this->loginAs($manager)->getJson('/api/v1/leave/approvals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requestId);

        $this->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING)
            ->assertJsonPath('data.workflow.current_step.key', 'hr_admin');

        $this->artisan('outbox:process', ['--once' => true])->assertSuccessful();

        $hrNotification = Notification::query()
            ->where('notifiable_id', $hr->getKey())
            ->where('type', 'leave.approval_required')
            ->firstOrFail();

        $this->assertNull($hrNotification->read_at);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $manager->getKey(),
            'type' => 'leave.approval_required',
        ]);

        $this->loginAs($hr)->postJson("/api/v1/leave/approvals/{$requestId}/decision", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_APPROVED)
            ->assertJsonPath('data.workflow.status', 'approved');

        $this->artisan('outbox:process', ['--once' => true])->assertSuccessful();

        $employeeNotification = Notification::query()
            ->where('notifiable_id', $employee->getKey())
            ->where('type', 'leave.approved')
            ->firstOrFail();

        $this->assertSame('Leave request approved', $employeeNotification->data['title']);
        $this->assertSame($requestId, $employeeNotification->data['context']['leave_request_id']);

        $balance = $this->balance($employee, $type, 2027);
        $this->assertSame('0.00', $balance->reserved);
        $this->assertSame('2.00', $balance->used);

        $this->loginAs($employee)->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'leave.approved');

        $notificationId = $employeeNotification->getKey();

        $this->postJson("/api/v1/notifications/{$notificationId}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $notificationId);

        $this->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->postJson('/api/v1/notifications/read-all')->assertOk();

        $this->assertTrue(AuditLog::query()->where('action', 'leave.request.submitted')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'leave.request.approve')->exists());
        $this->assertSame(
            OutboxMessage::STATUS_PUBLISHED,
            OutboxMessage::query()->where('aggregate_id', $requestId)->latest('id')->firstOrFail()->status,
        );
    }
}
