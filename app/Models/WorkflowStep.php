<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowStep extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'workflow_instance_id',
        'step_key',
        'position',
        'status',
        'assignee_type',
        'assignee_id',
        'required_permissions',
        'due_at',
        'activated_at',
        'acted_at',
        'acted_by',
        'action',
        'action_payload',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'required_permissions' => 'array',
            'action_payload' => 'array',
            'due_at' => 'datetime',
            'activated_at' => 'datetime',
            'acted_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(WorkflowEscalation::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    public function isActionable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_ACTIVE], true);
    }
}
