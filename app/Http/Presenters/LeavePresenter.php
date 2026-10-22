<?php

namespace App\Http\Presenters;

use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkflowStep;
use Spatie\Permission\Models\Role;

class LeavePresenter
{
    public static function request(LeaveRequest $request): array
    {
        $request->loadMissing(['user', 'type', 'manager', 'workflow.steps']);

        return [
            'id' => $request->getKey(),
            'status' => $request->status,
            'employee' => self::user($request->user),
            'leave_type' => $request->type === null ? null : [
                'id' => $request->type->getKey(),
                'code' => $request->type->code,
                'name' => $request->type->name,
            ],
            'manager' => self::user($request->manager),
            'start_date' => $request->start_date->toDateString(),
            'end_date' => $request->end_date->toDateString(),
            'days' => (float) $request->days,
            'reason' => $request->reason,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'canceled_at' => $request->canceled_at?->toIso8601String(),
            'cancel_reason' => $request->cancel_reason,
            'workflow' => $request->workflow === null ? null : self::workflow($request),
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];
    }

    public static function cancellation(LeaveCancellationRequest $cancellation): array
    {
        $cancellation->loadMissing(['requester', 'manager', 'workflow.steps']);

        return [
            'id' => $cancellation->getKey(),
            'leave_request_id' => $cancellation->leave_request_id,
            'status' => $cancellation->status,
            'reason' => $cancellation->reason,
            'requester' => self::user($cancellation->requester),
            'manager' => self::user($cancellation->manager),
            'decided_by' => $cancellation->decided_by,
            'decided_at' => $cancellation->decided_at?->toIso8601String(),
            'workflow' => $cancellation->workflow === null ? null : self::workflowFor($cancellation->workflow),
            'created_at' => $cancellation->created_at?->toIso8601String(),
        ];
    }

    protected static function workflow(LeaveRequest $request): array
    {
        return self::workflowFor($request->workflow);
    }

    protected static function workflowFor($workflow): array
    {
        $steps = $workflow->steps->sortBy('position')->values();

        $current = $steps->first(fn (WorkflowStep $step) => in_array($step->status, [
            WorkflowStep::STATUS_PENDING,
            WorkflowStep::STATUS_ACTIVE,
        ], true));

        return [
            'id' => $workflow->getKey(),
            'status' => $workflow->status,
            'current_step' => $current === null ? null : self::step($current),
            'steps' => $steps->map(fn (WorkflowStep $step) => self::step($step))->all(),
        ];
    }

    protected static function assigneeName(WorkflowStep $step): ?string
    {
        if ($step->assignee_type === 'user' && $step->assignee_id !== null) {
            $user = User::query()->find($step->assignee_id);

            return $user?->name;
        }

        if ($step->assignee_type === 'role' && $step->assignee_id !== null) {
            $role = Role::query()->find($step->assignee_id);

            return $role === null ? null : ucfirst(str_replace('_', ' ', $role->name));
        }

        return null;
    }

    protected static function step(WorkflowStep $step): array
    {
        return [
            'key' => $step->step_key,
            'position' => $step->position,
            'status' => $step->status,
            'assignee_type' => $step->assignee_type,
            'assignee_id' => $step->assignee_id,
            'assignee_name' => self::assigneeName($step),
            'due_at' => $step->due_at?->toIso8601String(),
            'acted_at' => $step->acted_at?->toIso8601String(),
            'acted_by' => $step->acted_by,
            'action' => $step->action,
        ];
    }

    protected static function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
