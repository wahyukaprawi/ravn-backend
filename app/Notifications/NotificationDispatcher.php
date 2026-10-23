<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\NotificationDelivery;
use App\Models\OutboxMessage;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStep;
use App\Services\AnnouncementService;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AnnouncementService $announcements,
    ) {}

    public function dispatch(OutboxMessage $message): int
    {
        $payload = $message->payload ?? [];

        return match ($message->event_type) {
            'leave.request.submitted',
            'leave.request.held',
            'leave.request.approver_assigned' => $this->notify(
                $message,
                $this->activeUsers([$payload['manager_id'] ?? null]),
                'leave.approval_required',
                'Leave request needs review',
                'A leave request is waiting for your decision.',
                ['leave_request_id' => $payload['leave_request_id'] ?? null],
            ),
            'leave.request.stage_approved' => $this->notify(
                $message,
                $this->nextLeaveApprovers($payload),
                'leave.approval_required',
                'Leave request needs review',
                'A leave request is waiting for your decision.',
                ['leave_request_id' => $payload['leave_request_id'] ?? null],
            ),
            'leave.request.approved' => $this->notify(
                $message,
                $this->activeUsers([$payload['employee_id'] ?? null]),
                'leave.approved',
                'Leave request approved',
                'Your leave request was approved.',
                ['leave_request_id' => $payload['leave_request_id'] ?? null, 'status' => 'approved'],
            ),
            'leave.request.rejected' => $this->notify(
                $message,
                $this->activeUsers([$payload['employee_id'] ?? null]),
                'leave.rejected',
                'Leave request rejected',
                'Your leave request was rejected.',
                ['leave_request_id' => $payload['leave_request_id'] ?? null, 'status' => 'rejected'],
            ),
            'leave.request.canceled' => $this->notify(
                $message,
                $this->activeUsers([$payload['employee_id'] ?? null]),
                'leave.canceled',
                'Leave request canceled',
                'Your leave request was canceled.',
                ['leave_request_id' => $payload['leave_request_id'] ?? null, 'status' => 'canceled'],
            ),
            'leave.request.cancellation_requested' => $this->notify(
                $message,
                $this->cancellationApprovers($payload),
                'leave.cancellation_required',
                'Leave cancellation needs review',
                'A leave cancellation request is waiting for your decision.',
                [
                    'leave_request_id' => $payload['leave_request_id'] ?? null,
                    'cancellation_request_id' => $payload['cancellation_request_id'] ?? null,
                ],
            ),
            'leave.sla.reminder' => $this->notify(
                $message,
                $this->assigneeUsers(
                    $payload['escalate_to_type'] ?? $payload['assignee_type'] ?? null,
                    $payload['escalate_to_id'] ?? $payload['assignee_id'] ?? null,
                ),
                'leave.overdue',
                'Approval overdue',
                'A leave approval step is overdue.',
                [
                    'leave_request_id' => $payload['leave_request_id'] ?? null,
                    'cancellation_request_id' => $payload['cancellation_request_id'] ?? null,
                    'workflow_step_id' => $payload['workflow_step_id'] ?? null,
                ],
            ),
            'attendance.correction.submitted' => $this->notify(
                $message,
                $this->correctionManager($payload),
                'attendance.correction.required',
                'Attendance correction needs review',
                'An attendance correction is waiting for review.',
                ['correction_id' => $payload['correction_id'] ?? null],
            ),
            'attendance.correction.approved',
            'attendance.correction.rejected',
            'attendance.correction.executed' => $this->notify(
                $message,
                $this->activeUsers([$payload['requester_id'] ?? null]),
                'attendance.correction.updated',
                'Attendance correction updated',
                $this->correctionMessage($message->event_type),
                ['correction_id' => $payload['correction_id'] ?? null],
            ),
            'report.export.ready' => $this->notify(
                $message,
                $this->exportOwner($payload),
                'report.export.ready',
                'Report export ready',
                'Your report export is ready to download.',
                ['export_id' => $payload['export_id'] ?? null, 'report_code' => $payload['report_code'] ?? null],
            ),
            'report.export.failed' => $this->notify(
                $message,
                $this->exportOwner($payload),
                'report.export.failed',
                'Report export failed',
                'Your report export could not be generated.',
                ['export_id' => $payload['export_id'] ?? null, 'report_code' => $payload['report_code'] ?? null],
            ),
            'announcement.published' => $this->notify(
                $message,
                $this->announcementRecipients($payload),
                'announcement.published',
                'New announcement',
                'A new announcement has been published.',
                ['announcement_id' => $payload['announcement_id'] ?? null],
                [NotificationDelivery::CHANNEL_IN_APP],
            ),
            default => 0,
        };
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @param  array<string, mixed>  $context
     */
    protected function notify(OutboxMessage $message, Collection $recipients, string $type, string $title, string $body, array $context, ?array $channels = null): int
    {
        $created = 0;

        foreach ($recipients->unique('id') as $recipient) {
            if (! $recipient instanceof User || ! $recipient->isActive()) {
                continue;
            }

            if ($this->notifications->notify($message, $recipient, $type, $title, $body, $context, $channels) !== null) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  array<int, int|null>  $ids
     * @return Collection<int, User>
     */
    protected function activeUsers(array $ids): Collection
    {
        $ids = array_values(array_filter($ids, fn ($id) => $id !== null));

        if ($ids === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->where('status', User::STATUS_ACTIVE)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, User>
     */
    protected function nextLeaveApprovers(array $payload): Collection
    {
        $request = LeaveRequest::query()
            ->with('workflow.steps')
            ->find($payload['leave_request_id'] ?? null);

        $recipients = $request?->workflow instanceof WorkflowInstance
            ? $this->currentStepUsers($request->workflow)
            : collect();

        if ($recipients->isNotEmpty()) {
            return $recipients;
        }

        return $this->activeUsers([$payload['manager_id'] ?? $request?->manager_id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, User>
     */
    protected function cancellationApprovers(array $payload): Collection
    {
        $cancellation = LeaveCancellationRequest::query()
            ->with('workflow.steps')
            ->find($payload['cancellation_request_id'] ?? null);

        $recipients = $cancellation?->workflow instanceof WorkflowInstance
            ? $this->currentStepUsers($cancellation->workflow)
            : collect();

        if ($recipients->isNotEmpty()) {
            return $recipients;
        }

        return $this->activeUsers([$payload['manager_id'] ?? null]);
    }

    /**
     * @return Collection<int, User>
     */
    protected function currentStepUsers(WorkflowInstance $workflow): Collection
    {
        $step = $workflow->steps()
            ->whereIn('status', [WorkflowStep::STATUS_PENDING, WorkflowStep::STATUS_ACTIVE])
            ->orderBy('position')
            ->first();

        if ($step === null) {
            return collect();
        }

        return $this->assigneeUsers($step->assignee_type, $step->assignee_id);
    }

    /**
     * @return Collection<int, User>
     */
    protected function assigneeUsers(?string $type, ?int $id): Collection
    {
        if ($id === null) {
            return collect();
        }

        if ($type === 'user') {
            return $this->activeUsers([$id]);
        }

        if ($type === 'role') {
            $role = Role::query()->find($id);

            if ($role === null) {
                return collect();
            }

            return User::query()
                ->role($role->name)
                ->where('status', User::STATUS_ACTIVE)
                ->get();
        }

        return collect();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, User>
     */
    protected function correctionManager(array $payload): Collection
    {
        $requester = User::query()->find($payload['requester_id'] ?? null);

        if ($requester === null || $requester->manager_id === null) {
            return collect();
        }

        if ((int) $requester->manager_id === (int) $requester->getKey()) {
            return collect();
        }

        return $this->activeUsers([$requester->manager_id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, User>
     */
    protected function exportOwner(array $payload): Collection
    {
        $export = ReportExport::query()->find($payload['export_id'] ?? null);

        return $this->activeUsers([$export?->user_id]);
    }

    protected function announcementRecipients(array $payload): Collection
    {
        $announcement = Announcement::query()->find($payload['announcement_id'] ?? null);

        return $announcement
            ? $this->announcements->recipients($announcement)
            : collect();
    }

    protected function correctionMessage(string $eventType): string
    {
        return match ($eventType) {
            'attendance.correction.approved' => 'Your attendance correction was approved.',
            'attendance.correction.rejected' => 'Your attendance correction was rejected.',
            'attendance.correction.executed' => 'Your attendance correction was applied to your attendance record.',
            default => 'Your attendance correction was updated.',
        };
    }
}
