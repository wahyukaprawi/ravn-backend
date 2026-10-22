<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
