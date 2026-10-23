<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Schedule extends Model
{
    use SoftDeletes;

    public const DAYS = [
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
        7 => 'sunday',
    ];

    protected $fillable = [
        'user_id',
        'work_location_id',
        'name',
        'effective_from',
        'effective_to',
        'days',
        'start_time',
        'end_time',
        'overnight',
        'late_grace_minutes',
        'early_departure_grace_minutes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'overnight' => 'boolean',
            'late_grace_minutes' => 'integer',
            'early_departure_grace_minutes' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id');
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class);
    }

    public function isWorkdayFor(string $workDate): bool
    {
        $day = (int) Carbon::parse($workDate)->dayOfWeekIso;

        return in_array($day, array_map('intval', $this->days ?? []), true);
    }

    public function shiftBounds(string $workDate, string $timezone): array
    {
        $start = Carbon::parse($workDate.' '.$this->start_time, $timezone);
        $end = Carbon::parse($workDate.' '.$this->end_time, $timezone);

        if ($this->overnight || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }

    public function effectiveOn(string $date): bool
    {
        $to = $this->effective_to?->toDateString();

        return $this->effective_from->toDateString() <= $date
            && ($to === null || $to >= $date);
    }
}
