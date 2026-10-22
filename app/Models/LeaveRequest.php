<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveRequest extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_HELD = 'held';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'user_id',
        'leave_type_id',
        'manager_id',
        'workflow_instance_id',
        'start_date',
        'end_date',
        'days',
        'status',
        'reason',
        'submitted_by',
        'submitted_at',
        'canceled_by',
        'canceled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days' => 'decimal:2',
            'submitted_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(LeaveCancellationRequest::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_HELD, self::STATUS_APPROVED], true);
    }

    public function blocksDates(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_HELD, self::STATUS_APPROVED], true);
    }

    public function isOverlapping(): bool
    {
        return self::query()
            ->where('user_id', $this->user_id)
            ->whereKeyNot($this->getKey())
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_HELD, self::STATUS_APPROVED])
            ->where(function ($q) {
                $q->whereBetween('start_date', [$this->start_date, $this->end_date])
                    ->orWhereBetween('end_date', [$this->start_date, $this->end_date])
                    ->orWhere(function ($q2) {
                        $q2->where('start_date', '<=', $this->start_date)
                            ->where('end_date', '>=', $this->end_date);
                    });
            })
            ->exists();
    }
}
