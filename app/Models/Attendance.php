<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkLocation extends Model
{
    protected $fillable = ['name', 'address', 'latitude', 'longitude', 'radius_meters', 'timezone', 'active'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'radius_meters' => 'integer', 'active' => 'boolean'];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }
}

class Schedule extends Model
{
    protected $fillable = ['user_id', 'work_location_id', 'name', 'effective_from', 'effective_to', 'days', 'start_time', 'end_time', 'overnight', 'active'];

    protected function casts(): array
    {
        return ['days' => 'array', 'effective_from' => 'date', 'effective_to' => 'date', 'overnight' => 'boolean', 'active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id');
    }
}

class AttendanceSession extends Model
{
    protected $fillable = ['user_id', 'schedule_id', 'work_location_id', 'work_date', 'status', 'opened_at', 'closed_at', 'timezone'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }
}

class AttendanceEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['attendance_session_id', 'user_id', 'event_type', 'occurred_at', 'latitude', 'longitude', 'accuracy_meters', 'mock_location_detected', 'device_integrity', 'source', 'idempotency_key', 'metadata', 'actor_id', 'created_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'accuracy_meters' => 'decimal:2', 'mock_location_detected' => 'boolean', 'device_integrity' => 'array', 'metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

class AttendanceCorrection extends Model
{
    protected $fillable = ['attendance_session_id', 'requester_id', 'approver_id', 'executor_id', 'workflow_instance_id', 'status', 'changes', 'reason', 'evidence', 'submitted_at', 'approved_at', 'executed_at', 'rejected_at'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'evidence' => 'array', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'executed_at' => 'datetime', 'rejected_at' => 'datetime'];
    }
}

class FaceVerificationChallenge extends Model
{
    protected $fillable = ['user_id', 'challenge_id', 'status', 'attempts', 'max_attempts', 'expires_at', 'verified_at', 'failed_at', 'metadata'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'max_attempts' => 'integer', 'expires_at' => 'datetime', 'verified_at' => 'datetime', 'failed_at' => 'datetime', 'metadata' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return $this->status === 'pending' && $this->attempts < $this->max_attempts && $this->expires_at->isFuture();
    }
}
