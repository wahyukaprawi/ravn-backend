<?php

namespace App\Console\Commands;

use App\Models\WorkflowStep;
use App\Services\WorkflowEngine;
use Illuminate\Console\Command;

class EscalateWorkflows extends Command
{
    protected $signature = 'workflows:escalate';

    protected $description = 'Escalate overdue workflow steps';

    public function handle(WorkflowEngine $engine): int
    {
        $now = now();

        $steps = WorkflowStep::query()
            ->with('instance.definition')
            ->whereIn('status', [WorkflowStep::STATUS_PENDING, WorkflowStep::STATUS_ACTIVE])
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->get();

        $count = 0;

        foreach ($steps as $step) {
            if ($step->instance->isTerminal()) {
                continue;
            }

            $step->escalations()->create([
                'level' => ($step->escalations()->max('level') ?? 0) + 1,
                'escalate_to_type' => 'role',
                'escalate_to_id' => 0,
                'escalated_at' => $now,
            ]);

            $step->forceFill(['due_at' => $now->copy()->addHours(24)])->save();
            $count++;
        }

        $this->info("Escalated {$count} workflow steps.");

        return self::SUCCESS;
    }
}
