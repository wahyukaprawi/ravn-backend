<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceCorrection extends Model
{
    protected $fillable = [
        'attendance_session_id',
        'requester_id',
        'approver_id',
        'executor_id',
        'workflow_instance_id',
        'status',
        'changes',
        'reason',
        'evidence',
        'submitted_at',
        'approved_at',
        'executed_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'evidence' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }
}
