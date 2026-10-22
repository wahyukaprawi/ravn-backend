<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveRequest;
use App\Models\OutboxMessage;
use App\Models\WorkflowStep;

class LeaveSlaTest extends LeaveTestCase
{
    public function test_lv_t10_a_reminder_is_sent_before_the_deadline_and_not_duplicated(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->json('data.id');

        WorkflowStep::query()->where('position', 1)->update(['due_at' => now()->addHours(12)]);

        $this->artisan('leave:sla-remind')->assertSuccessful();

        $messages = OutboxMessage::query()->where('event_type', 'leave.sla.reminder')->get();

        $this->assertCount(1, $messages);
        $this->assertSame('before', $messages->first()->payload['stage']);

        $this->artisan('leave:sla-remind')->assertSuccessful();

        $this->assertSame(1, OutboxMessage::query()->where('event_type', 'leave.sla.reminder')->count());
        $this->assertSame(LeaveRequest::STATUS_PENDING, LeaveRequest::query()->findOrFail($requestId)->status);
    }

    public function test_lv_t10_an_overdue_step_is_escalated_but_never_approved(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $requestId = $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02')
            ->json('data.id');

        WorkflowStep::query()->where('position', 1)->update(['due_at' => now()->subHour()]);

        $this->artisan('leave:sla-remind')->assertSuccessful();

        $message = OutboxMessage::query()->where('event_type', 'leave.sla.reminder')->firstOrFail();

        $this->assertSame('after', $message->payload['stage']);
        $this->assertNotNull($message->payload['workflow_step_id']);

        $this->assertDatabaseCount('workflow_escalations', 1);
        $this->assertSame(LeaveRequest::STATUS_PENDING, LeaveRequest::query()->findOrFail($requestId)->status);
        $this->assertSame('in_progress', LeaveRequest::query()->findOrFail($requestId)->workflow->status);
        $this->assertSame(WorkflowStep::STATUS_ACTIVE, WorkflowStep::query()->where('position', 1)->firstOrFail()->status);
    }

    public function test_lv_t10_a_stage_within_the_deadline_gets_no_reminder(): void
    {
        [$employee, , $type] = $this->scenario(10);

        $this->submitRequest($employee, $type, '2027-03-01', '2027-03-02');

        WorkflowStep::query()->where('position', 1)->update(['due_at' => now()->addDays(5)]);

        $this->artisan('leave:sla-remind')->assertSuccessful();

        $this->assertSame(0, OutboxMessage::query()->where('event_type', 'leave.sla.reminder')->count());
    }
}
