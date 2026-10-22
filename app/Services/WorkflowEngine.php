<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowEvent;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStep;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class WorkflowEngine
{
    public function start(WorkflowDefinition $definition, $subject, array $context = [], ?User $initiator = null): WorkflowInstance
    {
        $initiator ??= Auth::user();

        return DB::transaction(function () use ($definition, $subject, $context, $initiator) {
            $instance = WorkflowInstance::query()->create([
                'workflow_definition_id' => $definition->getKey(),
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'status' => WorkflowInstance::STATUS_PENDING,
                'initiator_id' => $initiator?->getKey(),
                'context' => $context,
            ]);

            $this->recordEvent($instance, 'workflow.started', null, [
                'definition' => $definition->code,
                'version' => $definition->version,
            ]);

            $this->buildSteps($instance);

            return $instance->fresh(['steps', 'events', 'definition']);
        });
    }

    public function act(WorkflowStep $step, string $action, User $actor, array $payload = []): WorkflowInstance
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw new \InvalidArgumentException('Unsupported workflow action.');
        }

        return DB::transaction(function () use ($step, $action, $actor, $payload) {
            $instance = $step->instance()->lockForUpdate()->first();

            if ($instance->isTerminal()) {
                throw new \RuntimeException('Cannot act on a terminal workflow instance.');
            }

            $step = WorkflowStep::query()->whereKey($step->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->canAct($step, $actor)) {
                throw new AuthorizationException('You are not the authorized approver for this stage.');
            }

            $step->forceFill([
                'status' => $action === 'approve' ? WorkflowStep::STATUS_APPROVED : WorkflowStep::STATUS_REJECTED,
                'acted_at' => now(),
                'acted_by' => $actor->getKey(),
                'action' => $action,
                'action_payload' => $payload,
            ])->save();

            $this->recordEvent($instance, "step.{$action}", $step, [
                'step_key' => $step->step_key,
            ]);

            $this->advance($instance);

            return $instance->fresh(['steps', 'events']);
        });
    }

    public function cancel(WorkflowInstance $instance, User $actor, string $reason = ''): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $actor, $reason) {
            $instance = WorkflowInstance::query()
                ->whereKey($instance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($instance->isTerminal()) {
                return $instance;
            }

            $instance->forceFill([
                'status' => WorkflowInstance::STATUS_CANCELED,
                'canceled_at' => now(),
            ])->save();

            $this->recordEvent($instance, 'workflow.canceled', null, [
                'actor_id' => $actor->getKey(),
                'reason' => $reason,
            ]);

            return $instance->fresh(['steps', 'events']);
        });
    }

    public function currentStep(WorkflowInstance $instance): ?WorkflowStep
    {
        return $instance->steps()
            ->whereIn('status', [WorkflowStep::STATUS_PENDING, WorkflowStep::STATUS_ACTIVE])
            ->orderBy('position')
            ->first();
    }

    public function canAct(WorkflowStep $step, User $actor): bool
    {
        if (! $step->isActionable()) {
            return false;
        }

        foreach ((array) ($step->required_permissions ?? []) as $permission) {
            if (! $actor->hasPermissionTo($permission)) {
                return false;
            }
        }

        if ($step->assignee_type === 'user') {
            return (int) $step->assignee_id === (int) $actor->getKey();
        }

        if ($step->assignee_type === 'role') {
            $role = Role::query()->whereKey($step->assignee_id)->first();

            return $role !== null && $actor->hasRole($role->name);
        }

        return true;
    }

    public function assign(WorkflowStep $step, string $assigneeType, ?int $assigneeId): WorkflowStep
    {
        $step->forceFill([
            'assignee_type' => $assigneeType,
            'assignee_id' => $assigneeId,
        ])->save();

        return $step->fresh();
    }

    public function setDueAt(WorkflowStep $step, CarbonInterface $dueAt): WorkflowStep
    {
        $step->forceFill(['due_at' => $dueAt])->save();

        return $step->fresh();
    }

    public function recordReminder(WorkflowStep $step, string $stage): void
    {
        $this->recordEvent($step->instance, "sla.{$stage}_reminder", $step, [
            'step_key' => $step->step_key,
            'stage' => $stage,
        ]);
    }

    public function reminderAlreadySent(WorkflowStep $step, string $stage): bool
    {
        return WorkflowEvent::query()
            ->where('workflow_step_id', $step->getKey())
            ->where('event_type', "sla.{$stage}_reminder")
            ->exists();
    }

    protected function buildSteps(WorkflowInstance $instance): void
    {
        $schema = $instance->definition->schema ?? [];
        $steps = $schema['steps'] ?? [];

        $position = 0;

        foreach ($steps as $stepKey => $stepDef) {
            $position++;
            $workflowStep = WorkflowStep::query()->create([
                'workflow_instance_id' => $instance->getKey(),
                'step_key' => $stepKey,
                'position' => $position,
                'status' => $position === 1 ? WorkflowStep::STATUS_ACTIVE : WorkflowStep::STATUS_PENDING,
                'assignee_type' => $stepDef['assignee_type'] ?? null,
                'assignee_id' => $stepDef['assignee_id'] ?? null,
                'required_permissions' => $stepDef['required_permissions'] ?? null,
                'due_at' => isset($stepDef['due_in_hours']) ? now()->addHours((int) $stepDef['due_in_hours']) : null,
                'activated_at' => $position === 1 ? now() : null,
            ]);

            $this->recordEvent($instance, 'step.created', $workflowStep, [
                'step_key' => $stepKey,
                'position' => $position,
            ]);
        }

        if ($instance->steps()->count() > 0) {
            $instance->forceFill(['status' => WorkflowInstance::STATUS_IN_PROGRESS])->save();
        }
    }

    protected function advance(WorkflowInstance $instance): void
    {
        $current = $instance->steps()->where('status', WorkflowStep::STATUS_REJECTED)->first();

        if ($current !== null) {
            $instance->forceFill([
                'status' => WorkflowInstance::STATUS_REJECTED,
                'completed_at' => now(),
            ])->save();

            $this->recordEvent($instance, 'workflow.rejected', $current, ['step_key' => $current->step_key]);

            return;
        }

        $active = $instance->steps()->whereIn('status', [WorkflowStep::STATUS_PENDING, WorkflowStep::STATUS_ACTIVE])->orderBy('position')->first();

        if ($active === null) {
            $instance->forceFill([
                'status' => WorkflowInstance::STATUS_APPROVED,
                'completed_at' => now(),
            ])->save();

            $this->recordEvent($instance, 'workflow.approved', null, []);

            return;
        }

        if ($active->status === WorkflowStep::STATUS_PENDING) {
            $active->forceFill([
                'status' => WorkflowStep::STATUS_ACTIVE,
                'activated_at' => now(),
            ])->save();

            $this->recordEvent($instance, 'step.activated', $active, ['step_key' => $active->step_key]);
        }
    }

    protected function recordEvent(WorkflowInstance $instance, string $eventType, ?WorkflowStep $step, array $payload): void
    {
        WorkflowEvent::query()->create([
            'workflow_instance_id' => $instance->getKey(),
            'workflow_step_id' => $step?->getKey(),
            'event_type' => $eventType,
            'actor_id' => Auth::id(),
            'payload' => $payload,
            'request_id' => app()->bound('request_id') ? app('request_id') : null,
            'created_at' => now(),
        ]);
    }
}
