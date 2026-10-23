<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSession extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'user_id',
        'schedule_id',
        'work_location_id',
        'work_date',
        'status',
        'opened_at',
        'closed_at',
        'timezone',
        'scheduled_start_at',
        'scheduled_end_at',
        'late_minutes',
        'early_departure_minutes',
        'flags',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'late_minutes' => 'integer',
            'early_departure_minutes' => 'integer',
            'flags' => 'array',
        ];
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

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function checkInEvent(): ?AttendanceEvent
    {
        return $this->events->firstWhere('event_type', AttendanceEvent::TYPE_CHECK_IN);
    }

    public function checkOutEvent(): ?AttendanceEvent
    {
        return $this->events->firstWhere('event_type', AttendanceEvent::TYPE_CHECK_OUT);
    }
}
