<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowEvent;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStep;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($step, $action, $actor, $payload) {
            $instance = $step->instance()->lockForUpdate()->first();

            if ($instance->isTerminal()) {
                throw new \RuntimeException('Cannot act on a terminal workflow instance.');
            }

            if (! $step->isActionable()) {
                throw new \RuntimeException('Step is not actionable.');
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
            $instance = $instance->lockForUpdate();

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
