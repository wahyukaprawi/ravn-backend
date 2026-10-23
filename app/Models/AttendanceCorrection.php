<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrection extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'attendance_session_id',
        'requester_id',
        'approver_id',
        'executor_id',
        'workflow_instance_id',
        'status',
        'changes',
        'before',
        'reason',
        'evidence_path',
        'evidence',
        'submitted_at',
        'decided_at',
        'approved_at',
        'executed_at',
        'rejected_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'before' => 'array',
            'evidence' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executor_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_EXECUTED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
