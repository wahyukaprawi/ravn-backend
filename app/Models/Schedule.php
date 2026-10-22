<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    protected $fillable = ['user_id', 'work_location_id', 'name', 'effective_from', 'effective_to', 'days', 'start_time', 'end_time', 'overnight', 'active'];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'overnight' => 'boolean',
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
}
