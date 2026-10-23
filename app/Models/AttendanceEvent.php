<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEvent extends Model
{
    public const TYPE_CHECK_IN = 'check_in';

    public const TYPE_CHECK_OUT = 'check_out';

    public const VERIFICATION_UNVERIFIED = 'unverified';

    public const VERIFICATION_CLAIMED = 'claimed';

    public const VERIFICATION_FLAGGED = 'flagged';

    public const UPDATED_AT = null;

    protected $fillable = [
        'attendance_session_id',
        'schedule_id',
        'work_location_id',
        'user_id',
        'event_type',
        'occurred_at',
        'latitude',
        'longitude',
        'accuracy_meters',
        'distance_meters',
        'face_verified',
        'verification_status',
        'face_challenge_id',
        'device_id',
        'ip_address',
        'flags',
        'corrected_at',
        'corrected_by',
        'attendance_correction_id',
        'mock_location_detected',
        'device_integrity',
        'source',
        'idempotency_key',
        'metadata',
        'actor_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_meters' => 'decimal:2',
            'distance_meters' => 'decimal:2',
            'face_verified' => 'boolean',
            'flags' => 'array',
            'corrected_at' => 'datetime',
            'mock_location_detected' => 'boolean',
            'device_integrity' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(AttendanceCorrection::class, 'attendance_correction_id');
    }
}
