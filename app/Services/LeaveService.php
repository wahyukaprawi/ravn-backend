<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public const SLA_WORKDAYS = 2;

    public function __construct(
        private readonly WorkCalendarService $calendar,
        private readonly LeaveBalanceService $balances,
        private readonly WorkflowEngine $workflow,
        private readonly ManagerResolver $managers,
        private readonly Outbox $outbox,
        private readonly AuditRecorder $audit,
    ) {}

    public function submit(User $user, array $data): LeaveRequest
    {
        return $this->transaction(function () use ($user, $data) {
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->calendar->validateTimezoneResolvable($user);

            $days = $this->calendar->countEligibleWorkdays($user, $data['start_date'], $data['end_date']);

            if ($days <= 0) {
                throw ValidationException::withMessages(['start_date' => 'Request must cover at least one eligible workday.']);
            }

            $overlap = LeaveRequest::query()
                ->where('user_id', $user->getKey())
                ->whereIn('status', [
                    LeaveRequest::STATUS_PENDING,
                    LeaveRequest::STATUS_HELD,
                    LeaveRequest::STATUS_APPROVED,
                ])
                ->where(function ($query) use ($data) {
                    $query->whereBetween('start_date', [$data['start_date'], $data['end_date']])
                        ->orWhereBetween('end_date', [$data['start_date'], $data['end_date']])
                        ->orWhere(function ($inner) use ($data) {
                            $inner->where('start_date', '<=', $data['start_date'])
                                ->where('end_date', '>=', $data['end_date']);
                        });
                })
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages(['start_date' => 'This request overlaps with an existing pending or approved leave request.']);
            }

            $manager = $this->managers->for($user);
            $definition = $this->definition('leave_approval');

            $status = $manager !== null && $definition !== null
                ? LeaveRequest::STATUS_PENDING
                : LeaveRequest::STATUS_HELD;

            $request = LeaveRequest::query()->create([
                'user_id' => $user->getKey(),
                'leave_type_id' => $data['leave_type_id'],
                'manager_id' => $manager?->getKey(),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'days' => $days,
                'status' => $status,
                'reason' => $data['reason'] ?? null,
                'submitted_by' => $user->getKey(),
                'submitted_at' => now(),
            ]);

            $this->balances->reserve($user, $request, $days);

            if ($status === LeaveRequest::STATUS_PENDING) {
                $instance = $this->startApproval($request, $manager, $definition);

                $request->forceFill(['workflow_instance_id' => $instance->getKey()])->save();
            }

            $this->audit->record('leave.request.submitted', $request, null, [
                'status' => $status,
                'days' => $days,
                'manager_id' => $manager?->getKey(),
            ], $user);

            $this->outbox->dispatch($this->eventForStatus($status), [
                'leave_request_id' => $request->getKey(),
                'employee_id' => $user->getKey(),
                'manager_id' => $manager?->getKey(),
                'leave_type_id' => $request->leave_type_id,
                'start_date' => $request->start_date->toDateString(),
                'end_date' => $request->end_date->toDateString(),
                'days' => $days,
                'status' => $status,
            ], 'leave_request', $request->getKey());

            return $request->fresh(['type', 'workflow.steps', 'manager']);
        });
    }

    public function decide(LeaveRequest $request, string $action, User $actor, array $payload = []): LeaveRequest
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['action' => 'Action must be approve or reject.']);
        }

        return $this->transaction(function () use ($request, $action, $actor, $payload) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($request->status !== LeaveRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'Only pending requests can be decided.']);
            }

            if ((int) $request->user_id === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['actor' => 'Self-approval is blocked.']);
            }

            $instance = $request->workflow;

            if ($instance === null) {
                throw ValidationException::withMessages(['workflow' => 'This request is held for authorized handling.']);
            }

            $step = $this->workflow->currentStep($instance);

            if ($step === null) {
                throw ValidationException::withMessages(['workflow' => 'No actionable approval step.']);
            }

            $this->workflow->act($step, $action, $actor, $payload);

            $instance = $instance->fresh();

            if ($action === 'reject') {
                $request->forceFill(['status' => LeaveRequest::STATUS_REJECTED])->save();

                $this->balances->release($request->user, $request, (float) $request->days);

                $event = 'leave.request.rejected';
            } elseif ($instance->status === WorkflowInstance::STATUS_APPROVED) {
                $request->forceFill(['status' => LeaveRequest::STATUS_APPROVED])->save();

                $this->balances->consume($request->user, $request, (float) $request->days);

                $event = 'leave.request.approved';
            } else {
                $next = $this->workflow->currentStep($instance);

                if ($next !== null) {
                    $this->workflow->setDueAt($next, $this->calendar->addWorkdays($request->user, now(), self::SLA_WORKDAYS));
                }

                $event = 'leave.request.stage_approved';
            }

            $this->audit->record("leave.request.{$action}", $request, null, [
                'status' => $request->status,
                'step' => $step->step_key,
                'workflow_status' => $instance->status,
            ], $actor);

            $this->outbox->dispatch($event, [
                'leave_request_id' => $request->getKey(),
                'employee_id' => $request->user_id,
                'manager_id' => $request->manager_id,
                'actor_id' => $actor->getKey(),
                'status' => $request->status,
                'workflow_status' => $instance->status,
                'step' => $step->step_key,
            ], 'leave_request', $request->getKey());

            return $request->fresh(['type', 'workflow.steps', 'manager']);
        });
    }

    public function requestCancellation(LeaveRequest $request, User $actor, string $reason): array
    {
        return $this->transaction(function () use ($request, $actor, $reason) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ((int) $request->user_id !== (int) $actor->getKey() && ! $actor->hasRole('super_admin')) {
                throw new AuthorizationException('Only the request owner can cancel it.');
            }

            if (in_array($request->status, [LeaveRequest::STATUS_CANCELED, LeaveRequest::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages(['status' => 'This request cannot be canceled.']);
            }

            if ($request->status === LeaveRequest::STATUS_PENDING || $request->status === LeaveRequest::STATUS_HELD) {
                $previousStatus = $request->status;

                $request->forceFill([
                    'status' => LeaveRequest::STATUS_CANCELED,
                    'canceled_by' => $actor->getKey(),
                    'canceled_at' => now(),
                    'cancel_reason' => $reason,
                ])->save();

                $this->balances->release($request->user, $request, (float) $request->days);

                if ($request->workflow !== null && ! $request->workflow->isTerminal()) {
                    $this->workflow->cancel($request->workflow, $actor, $reason);
                }

                $this->audit->record('leave.request.canceled', $request, ['status' => $previousStatus], [
                    'status' => LeaveRequest::STATUS_CANCELED,
                    'reason' => $reason,
                ], $actor);

                $this->outbox->dispatch('leave.request.canceled', [
                    'leave_request_id' => $request->getKey(),
                    'employee_id' => $request->user_id,
                    'manager_id' => $request->manager_id,
                    'actor_id' => $actor->getKey(),
                ], 'leave_request', $request->getKey());

                return ['mode' => 'canceled', 'request' => $request->fresh(['type', 'workflow.steps', 'manager'])];
            }

            $today = $this->calendar->todayFor($request->user);
            $start = Carbon::parse($request->start_date->toDateString(), $today->getTimezone())->startOfDay();

            if ($start->lessThanOrEqualTo($today)) {
                throw ValidationException::withMessages(['status' => 'Approved leave that has started cannot be canceled normally.']);
            }

            $existing = $request->cancellationRequests()
                ->where('status', LeaveCancellationRequest::STATUS_PENDING)
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages(['status' => 'A cancellation request is already pending.']);
            }

            $manager = $request->manager_id !== null
                ? User::query()->whereKey($request->manager_id)->where('status', User::STATUS_ACTIVE)->first()
                : $this->managers->for($request->user);

            if ($manager !== null && (int) $manager->getKey() === (int) $request->user_id) {
                $manager = null;
            }

            $definition = $this->definition('leave_cancellation');

            $cancellation = LeaveCancellationRequest::query()->create([
                'leave_request_id' => $request->getKey(),
                'requester_id' => $actor->getKey(),
                'manager_id' => $manager?->getKey(),
                'status' => LeaveCancellationRequest::STATUS_PENDING,
                'reason' => $reason,
            ]);

            if ($manager !== null && $definition !== null) {
                $instance = $this->startCancellationApproval($cancellation, $manager, $definition);

                $cancellation->forceFill(['workflow_instance_id' => $instance->getKey()])->save();
            }

            $this->audit->record('leave.request.cancellation_requested', $request, null, [
                'cancellation_request_id' => $cancellation->getKey(),
                'manager_id' => $manager?->getKey(),
                'reason' => $reason,
            ], $actor);

            $this->outbox->dispatch('leave.request.cancellation_requested', [
                'leave_request_id' => $request->getKey(),
                'cancellation_request_id' => $cancellation->getKey(),
                'employee_id' => $request->user_id,
                'manager_id' => $manager?->getKey(),
                'actor_id' => $actor->getKey(),
            ], 'leave_request', $request->getKey());

            return ['mode' => 'pending_approval', 'cancellation' => $cancellation->fresh(['workflow.steps'])];
        });
    }

    public function decideCancellation(LeaveCancellationRequest $cancellation, string $action, User $actor, array $payload = []): LeaveCancellationRequest
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['action' => 'Action must be approve or reject.']);
        }

        return $this->transaction(function () use ($cancellation, $action, $actor, $payload) {
            $cancellation = LeaveCancellationRequest::query()->lockForUpdate()->findOrFail($cancellation->getKey());

            if (! $cancellation->isPending()) {
                throw ValidationException::withMessages(['status' => 'Only pending cancellation requests can be decided.']);
            }

            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($cancellation->leave_request_id);

            if ($request->status !== LeaveRequest::STATUS_APPROVED) {
                $cancellation->forceFill([
                    'status' => LeaveCancellationRequest::STATUS_REJECTED,
                    'decided_by' => $actor->getKey(),
                    'decided_at' => now(),
                ])->save();

                $this->audit->record('leave.request.cancellation_rejected', $cancellation, null, [
                    'reason' => 'The leave request is no longer approved.',
                ], $actor);

                return $cancellation->fresh(['workflow.steps']);
            }

            if ((int) $request->user_id === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['actor' => 'Self-approval is blocked.']);
            }

            if ($cancellation->workflow_instance_id !== null) {
                $instance = $cancellation->workflow;
                $step = $this->workflow->currentStep($instance);

                if ($step === null) {
                    throw ValidationException::withMessages(['workflow' => 'No actionable approval step.']);
                }

                $this->workflow->act($step, $action, $actor, $payload);

                $instance = $instance->fresh();

                if ($action === 'approve' && $instance->status !== WorkflowInstance::STATUS_APPROVED) {
                    $next = $this->workflow->currentStep($instance);

                    if ($next !== null) {
                        $this->workflow->setDueAt($next, $this->calendar->addWorkdays($request->user, now(), self::SLA_WORKDAYS));
                    }

                    $this->audit->record('leave.request.cancellation_stage_approved', $cancellation, null, [
                        'step' => $step->step_key,
                        'workflow_status' => $instance->status,
                    ], $actor);

                    return $cancellation->fresh(['workflow.steps']);
                }

                if ($action === 'reject') {
                    $cancellation->forceFill([
                        'status' => LeaveCancellationRequest::STATUS_REJECTED,
                        'decided_by' => $actor->getKey(),
                        'decided_at' => now(),
                    ])->save();

                    $this->audit->record('leave.request.cancellation_rejected', $cancellation, null, [
                        'reason' => $payload['reason'] ?? null,
                    ], $actor);

                    return $cancellation->fresh(['workflow.steps']);
                }
            } else {
                if (! $actor->hasPermissionTo('leave_requests.view_any') || ! $actor->hasPermissionTo('workflow.reassign')) {
                    throw new AuthorizationException('This cancellation is held for authorized handling.');
                }

                if ($action === 'reject') {
                    $cancellation->forceFill([
                        'status' => LeaveCancellationRequest::STATUS_REJECTED,
                        'decided_by' => $actor->getKey(),
                        'decided_at' => now(),
                    ])->save();

                    $this->audit->record('leave.request.cancellation_rejected', $cancellation, null, [
                        'reason' => $payload['reason'] ?? null,
                    ], $actor);

                    return $cancellation->fresh();
                }
            }

            $request->forceFill([
                'status' => LeaveRequest::STATUS_CANCELED,
                'canceled_by' => $actor->getKey(),
                'canceled_at' => now(),
                'cancel_reason' => $cancellation->reason,
            ])->save();

            $this->balances->refund($request->user, $request, (float) $request->days);

            $cancellation->forceFill([
                'status' => LeaveCancellationRequest::STATUS_APPROVED,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
            ])->save();

            $this->audit->record('leave.request.cancellation_approved', $request, ['status' => LeaveRequest::STATUS_APPROVED], [
                'status' => LeaveRequest::STATUS_CANCELED,
                'cancellation_request_id' => $cancellation->getKey(),
            ], $actor);

            $this->outbox->dispatch('leave.request.canceled', [
                'leave_request_id' => $request->getKey(),
                'cancellation_request_id' => $cancellation->getKey(),
                'employee_id' => $request->user_id,
                'manager_id' => $request->manager_id,
                'actor_id' => $actor->getKey(),
            ], 'leave_request', $request->getKey());

            return $cancellation->fresh(['workflow.steps', 'request']);
        });
    }

    public function assignApprover(LeaveRequest $request, User $actor, int $managerId): LeaveRequest
    {
        return $this->transaction(function () use ($request, $actor, $managerId) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($request->status !== LeaveRequest::STATUS_HELD) {
                throw ValidationException::withMessages(['status' => 'Only held requests can be assigned an approver.']);
            }

            $manager = User::query()
                ->whereKey($managerId)
                ->where('status', User::STATUS_ACTIVE)
                ->firstOrFail();

            if ((int) $manager->getKey() === (int) $request->user_id) {
                throw ValidationException::withMessages(['manager_id' => 'Self-approval is blocked.']);
            }

            $definition = $this->definition('leave_approval');

            if ($definition === null) {
                throw ValidationException::withMessages(['workflow' => 'Leave approval workflow is not configured.']);
            }

            $request->forceFill([
                'manager_id' => $manager->getKey(),
                'status' => LeaveRequest::STATUS_PENDING,
            ])->save();

            $instance = $this->startApproval($request, $manager, $definition);

            $request->forceFill(['workflow_instance_id' => $instance->getKey()])->save();

            $this->audit->record('leave.request.approver_assigned', $request, ['status' => LeaveRequest::STATUS_HELD], [
                'status' => LeaveRequest::STATUS_PENDING,
                'manager_id' => $manager->getKey(),
            ], $actor);

            $this->outbox->dispatch('leave.request.approver_assigned', [
                'leave_request_id' => $request->getKey(),
                'employee_id' => $request->user_id,
                'manager_id' => $manager->getKey(),
                'actor_id' => $actor->getKey(),
            ], 'leave_request', $request->getKey());

            return $request->fresh(['type', 'workflow.steps', 'manager']);
        });
    }

    protected function startApproval(LeaveRequest $request, User $manager, WorkflowDefinition $definition): WorkflowInstance
    {
        $instance = $this->workflow->start($definition, $request, [
            'leave_request_id' => $request->getKey(),
            'manager_id' => $manager->getKey(),
        ], $request->user);

        $step = $this->workflow->currentStep($instance);

        if ($step !== null) {
            $this->workflow->assign($step, 'user', $manager->getKey());
            $this->workflow->setDueAt($step, $this->calendar->addWorkdays($request->user, now(), self::SLA_WORKDAYS));
        }

        return $instance;
    }

    protected function startCancellationApproval(LeaveCancellationRequest $cancellation, User $manager, WorkflowDefinition $definition): WorkflowInstance
    {
        $request = $cancellation->request;

        $instance = $this->workflow->start($definition, $cancellation, [
            'leave_request_id' => $request->getKey(),
            'cancellation_request_id' => $cancellation->getKey(),
            'manager_id' => $manager->getKey(),
        ], $cancellation->requester);

        $step = $this->workflow->currentStep($instance);

        if ($step !== null) {
            $this->workflow->assign($step, 'user', $manager->getKey());
            $this->workflow->setDueAt($step, $this->calendar->addWorkdays($request->user, now(), self::SLA_WORKDAYS));
        }

        return $instance;
    }

    protected function transaction(Closure $callback)
    {
        return DB::transaction($callback, 3);
    }

    protected function definition(string $code): ?WorkflowDefinition
    {
        return WorkflowDefinition::query()
            ->where('code', $code)
            ->where('active', true)
            ->first();
    }

    protected function eventForStatus(string $status): string
    {
        return $status === LeaveRequest::STATUS_HELD
            ? 'leave.request.held'
            : 'leave.request.submitted';
    }
}
