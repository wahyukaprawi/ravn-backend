<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowEscalation extends Model
{
    protected $fillable = [
        'workflow_step_id',
        'level',
        'escalate_to_type',
        'escalate_to_id',
        'escalated_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }
}
