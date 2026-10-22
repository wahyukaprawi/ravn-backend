<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkCalendar extends Model
{
    protected $fillable = ['name', 'timezone', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function days(): HasMany
    {
        return $this->hasMany(WorkCalendarDay::class);
    }
}

class WorkCalendarDay extends Model
{
    protected $fillable = ['work_calendar_id', 'date', 'type', 'name'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkCalendar::class, 'work_calendar_id');
    }
}

class LeaveType extends Model
{
    protected $fillable = ['code', 'name', 'requires_approval', 'active'];

    protected function casts(): array
    {
        return ['requires_approval' => 'boolean', 'active' => 'boolean'];
    }

    public function policies(): HasMany
    {
        return $this->hasMany(LeavePolicy::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }
}

class LeavePolicy extends Model
{
    protected $fillable = ['leave_type_id', 'name', 'annual_entitlement_days', 'submission_deadline_days_before', 'active', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date', 'submission_deadline_days_before' => 'integer', 'annual_entitlement_days' => 'integer'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}

class LeaveBalance extends Model
{
    protected $fillable = ['user_id', 'leave_type_id', 'year', 'entitled', 'used', 'reserved', 'adjusted'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'entitled' => 'decimal:2', 'used' => 'decimal:2', 'reserved' => 'decimal:2', 'adjusted' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}

class LeaveBalanceLedger extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'leave_type_id', 'leave_request_id', 'entry_type', 'amount', 'reason', 'actor_id', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'metadata' => 'array', 'created_at' => 'datetime'];
    }
}

class LeaveRequest extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = ['user_id', 'leave_type_id', 'workflow_instance_id', 'start_date', 'end_date', 'days', 'status', 'reason', 'submitted_by', 'submitted_at', 'canceled_by', 'canceled_at', 'cancel_reason'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'days' => 'decimal:2', 'submitted_at' => 'datetime', 'canceled_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function isOverlapping(): bool
    {
        return self::query()
            ->where('user_id', $this->user_id)
            ->whereKeyNot($this->getKey())
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED])
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
