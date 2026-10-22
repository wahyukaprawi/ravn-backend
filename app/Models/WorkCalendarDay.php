<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
