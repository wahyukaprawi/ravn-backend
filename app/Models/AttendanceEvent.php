<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'attendance_session_id',
        'user_id',
        'event_type',
        'occurred_at',
        'latitude',
        'longitude',
        'accuracy_meters',
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
}
