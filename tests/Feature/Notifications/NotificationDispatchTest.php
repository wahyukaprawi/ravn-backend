<?php

namespace Tests\Feature\Notifications;

use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\OutboxMessage;
use App\Models\ReportExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class NotificationDispatchTest extends NotificationTestCase
{
    public function test_nt_t01_notifications_are_created_only_after_the_outbox_is_processed(): void
    {
        $message = $this->outbox('leave.request.approved', [
            'leave_request_id' => 42,
            'employee_id' => $this->employee->getKey(),
            'status' => 'approved',
        ]);

        $this->assertDatabaseCount('notifications', 0);

        $this->processOutbox();

        $this->assertDatabaseCount('notifications', 1);

        $notification = Notification::query()->firstOrFail();

        $this->assertSame($this->employee->getKey(), (int) $notification->notifiable_id);
        $this->assertSame('leave.approved', $notification->type);
        $this->assertSame('Leave request approved', $notification->data['title']);
        $this->assertSame('Your leave request was approved.', $notification->data['body']);
        $this->assertSame(42, $notification->data['context']['leave_request_id']);

        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $notification->getKey(),
            'channel' => NotificationDelivery::CHANNEL_IN_APP,
            'status' => NotificationDelivery::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $notification->getKey(),
            'channel' => NotificationDelivery::CHANNEL_EMAIL,
            'status' => NotificationDelivery::STATUS_PENDING,
        ]);
        $this->assertDatabaseMissing('notification_deliveries', [
            'notification_id' => $notification->getKey(),
            'channel' => NotificationDelivery::CHANNEL_PUSH,
        ]);

        $this->assertSame(OutboxMessage::STATUS_PUBLISHED, $message->fresh()->status);
        Queue::assertPushed(DeliverNotification::class, 1);
    }

    public function test_nt_t01_a_rolled_back_transaction_leaves_no_outbox_row_or_notification(): void
    {
        DB::beginTransaction();

        $message = $this->outbox('leave.request.approved', [
            'leave_request_id' => 43,
            'employee_id' => $this->employee->getKey(),
        ]);

        DB::rollBack();

        $this->assertDatabaseMissing('outbox_messages', ['id' => $message->getKey()]);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_nt_t02_reprocessing_the_same_outbox_message_does_not_duplicate_notifications(): void
    {
        $message = $this->outbox('leave.request.rejected', [
            'leave_request_id' => 44,
            'employee_id' => $this->employee->getKey(),
        ]);

        $this->processOutbox();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 2);

        $message->forceFill([
            'status' => OutboxMessage::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => now(),
            'locked_at' => null,
            'locked_by' => null,
            'processed_at' => null,
        ])->save();

        $this->processOutbox();

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 2);
        Queue::assertPushed(DeliverNotification::class, 1);
    }

    public function test_leave_submission_notifies_the_manager(): void
    {
        $this->outbox('leave.request.submitted', [
            'leave_request_id' => 45,
            'employee_id' => $this->employee->getKey(),
            'manager_id' => $this->manager->getKey(),
        ], 45);

        $this->processOutbox();

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->manager->getKey(),
            'type' => 'leave.approval_required',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $this->employee->getKey(),
        ]);
    }

    public function test_attendance_correction_decision_notifies_the_requester(): void
    {
        $this->outbox('attendance.correction.approved', [
            'correction_id' => 9,
            'requester_id' => $this->employee->getKey(),
            'approver_id' => $this->manager->getKey(),
        ], 9);

        $this->processOutbox();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->employee->getKey(),
            'type' => 'attendance.correction.updated',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $this->manager->getKey(),
        ]);
    }

    public function test_report_export_notification_goes_to_the_export_owner(): void
    {
        $export = ReportExport::query()->create([
            'user_id' => $this->employee->getKey(),
            'report_code' => 'attendance.daily',
            'status' => ReportExport::STATUS_READY,
            'format' => 'csv',
            'disk' => 'reports',
        ]);

        $this->outbox('report.export.ready', [
            'export_id' => $export->getKey(),
            'report_code' => 'attendance.daily',
            'row_count' => 4,
        ], $export->getKey());

        $this->processOutbox();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->employee->getKey(),
            'type' => 'report.export.ready',
        ]);
    }

    public function test_events_without_a_notification_mapping_are_ignored(): void
    {
        $message = $this->outbox('attendance.check_in', [
            'attendance_session_id' => 1,
            'user_id' => $this->employee->getKey(),
        ]);

        $this->processOutbox();

        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(OutboxMessage::STATUS_PUBLISHED, $message->fresh()->status);
    }
}
