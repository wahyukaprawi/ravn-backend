<?php

namespace App\Console\Commands;

use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\WorkflowStep;
use App\Services\Outbox;
use App\Services\WorkflowEngine;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class LeaveSlaReminders extends Command
{
    protected $signature = 'leave:sla-remind';

    protected $description = 'Send leave approval SLA reminders and record escalations';

    public function handle(WorkflowEngine $engine, Outbox $outbox): int
    {
        $now = now();
        $count = 0;

        $steps = WorkflowStep::query()
            ->with('instance')
            ->whereIn('status', [WorkflowStep::STATUS_PENDING, WorkflowStep::STATUS_ACTIVE])
            ->whereNotNull('due_at')
            ->whereHas('instance', function ($query) {
                $query->whereIn('subject_type', [LeaveRequest::class, LeaveCancellationRequest::class]);
            })
            ->get();

        foreach ($steps as $step) {
            if ($step->instance->isTerminal()) {
                continue;
            }

            $stage = match (true) {
                $step->due_at->isPast() => 'after',
                $step->due_at->lessThanOrEqualTo($now->copy()->addDay()) => 'before',
                default => null,
            };

            if ($stage === null || $engine->reminderAlreadySent($step, $stage)) {
                continue;
            }

            $engine->recordReminder($step, $stage);

            $escalateToType = null;
            $escalateToId = null;

            if ($stage === 'after') {
                $next = WorkflowStep::query()
                    ->where('workflow_instance_id', $step->workflow_instance_id)
                    ->where('position', '>', $step->position)
                    ->orderBy('position')
                    ->first();

                $escalateToType = $next?->assignee_type ?? 'role';
                $escalateToId = $next?->assignee_id ?? Role::findByName('super_admin', 'web')->getKey();

                $step->escalations()->create([
                    'level' => ($step->escalations()->max('level') ?? 0) + 1,
                    'escalate_to_type' => $escalateToType,
                    'escalate_to_id' => $escalateToId,
                    'escalated_at' => $now,
                ]);

                $step->forceFill(['due_at' => $now->copy()->addHours(24)])->save();
            }

            $context = $step->instance->context ?? [];

            $outbox->dispatch('leave.sla.reminder', [
                'leave_request_id' => $context['leave_request_id'] ?? null,
                'cancellation_request_id' => $context['cancellation_request_id'] ?? null,
                'workflow_instance_id' => $step->workflow_instance_id,
                'workflow_step_id' => $step->getKey(),
                'step_key' => $step->step_key,
                'assignee_type' => $step->assignee_type,
                'assignee_id' => $step->assignee_id,
                'escalate_to_type' => $escalateToType,
                'escalate_to_id' => $escalateToId,
                'stage' => $stage,
                'due_at' => $step->due_at?->toIso8601String(),
            ], 'workflow_step', $step->getKey());

            $count++;
        }

        $this->info("Leave SLA reminders sent: {$count}.");

        return self::SUCCESS;
    }
}
