<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function __construct(
        private readonly WorkCalendarService $calendar,
        private readonly LeaveBalanceService $balances,
        private readonly WorkflowEngine $workflow,
    ) {}

    public function submit(User $user, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($user, $data) {
            $this->calendar->validateTimezoneResolvable($user);

            $days = $this->calendar->countEligibleWorkdays($user, $data['start_date'], $data['end_date']);

            if ($days <= 0) {
                throw ValidationException::withMessages(['start_date' => 'Request must cover at least one eligible workday.']);
            }

            $overlap = LeaveRequest::query()
                ->where('user_id', $user->getKey())
                ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED])
                ->where(function ($q) use ($data) {
                    $q->whereBetween('start_date', [$data['start_date'], $data['end_date']])
                        ->orWhereBetween('end_date', [$data['start_date'], $data['end_date']])
                        ->orWhere(function ($q2) use ($data) {
                            $q2->where('start_date', '<=', $data['start_date'])
                                ->where('end_date', '>=', $data['end_date']);
                        });
                })
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages(['start_date' => 'This request overlaps with an existing pending or approved leave request.']);
            }

            $request = LeaveRequest::query()->create([
                'user_id' => $user->getKey(),
                'leave_type_id' => $data['leave_type_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'days' => $days,
                'status' => LeaveRequest::STATUS_PENDING,
                'reason' => $data['reason'] ?? null,
                'submitted_by' => $user->getKey(),
                'submitted_at' => now(),
            ]);

            $this->balances->reserve($user, $request, $days);

            $definition = WorkflowDefinition::query()->where('code', 'leave_approval')->where('active', true)->first();
            if ($definition) {
                $instance = $this->workflow->start($definition, $request, ['leave_request_id' => $request->getKey()], $user);
                $request->forceFill(['workflow_instance_id' => $instance->getKey()])->save();
            }

            return $request->fresh(['type', 'workflow']);
        });
    }

    public function decide(LeaveRequest $request, string $action, User $actor, array $payload = []): LeaveRequest
    {
        return DB::transaction(function () use ($request, $action, $actor, $payload) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($request->status !== LeaveRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'Only pending requests can be decided.']);
            }

            if ($request->user_id === $actor->getKey() && ! $actor->hasRole('super_admin')) {
                throw ValidationException::withMessages(['actor' => 'Self-approval is blocked.']);
            }

            if ($request->workflow && $request->workflow->isTerminal()) {
                throw ValidationException::withMessages(['workflow' => 'Workflow has already completed.']);
            }

            if ($action === 'approve') {
                if ($request->workflow && $request->workflow->status !== WorkflowInstance::STATUS_APPROVED) {
                    $step = $request->workflow->steps()->whereIn('status', ['pending', 'active'])->orderBy('position')->first();
                    if ($step) {
                        $this->workflow->act($step, 'approve', $actor, $payload);
                    }
                }

                $request->forceFill(['status' => LeaveRequest::STATUS_APPROVED])->save();
                $this->balances->consume($request->user, $request, (float) $request->days);
            } else {
                if ($request->workflow && $request->workflow->status !== WorkflowInstance::STATUS_REJECTED) {
                    $step = $request->workflow->steps()->whereIn('status', ['pending', 'active'])->orderBy('position')->first();
                    if ($step) {
                        $this->workflow->act($step, 'reject', $actor, $payload);
                    }
                }

                $request->forceFill(['status' => LeaveRequest::STATUS_REJECTED])->save();
                $this->balances->release($request->user, $request, (float) $request->days);
            }

            return $request->fresh(['type', 'workflow']);
        });
    }

    public function cancel(LeaveRequest $request, User $actor, string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($request->status === LeaveRequest::STATUS_CANCELED || $request->status === LeaveRequest::STATUS_REJECTED) {
                throw ValidationException::withMessages(['status' => 'This request cannot be canceled.']);
            }

            if ($request->status === LeaveRequest::STATUS_PENDING) {
                $request->forceFill([
                    'status' => LeaveRequest::STATUS_CANCELED,
                    'canceled_by' => $actor->getKey(),
                    'canceled_at' => now(),
                    'cancel_reason' => $reason,
                ])->save();

                $this->balances->release($request->user, $request, (float) $request->days);

                if ($request->workflow) {
                    $this->workflow->cancel($request->workflow, $actor, $reason);
                }

                return $request->fresh();
            }

            if ($request->status === LeaveRequest::STATUS_APPROVED) {
                $start = $request->start_date->startOfDay();

                if ($start->lte(now()->startOfDay())) {
                    throw ValidationException::withMessages(['status' => 'Approved leave that has started cannot be canceled normally.']);
                }

                $request->forceFill([
                    'status' => LeaveRequest::STATUS_CANCELED,
                    'canceled_by' => $actor->getKey(),
                    'canceled_at' => now(),
                    'cancel_reason' => $reason,
                ])->save();

                $this->balances->release($request->user, $request, (float) $request->days);

                return $request->fresh();
            }

            throw ValidationException::withMessages(['status' => 'This request cannot be canceled.']);
        });
    }
}
