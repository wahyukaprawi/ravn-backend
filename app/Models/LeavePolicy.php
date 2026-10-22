<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeavePolicy extends Model
{
    protected $fillable = ['leave_type_id', 'name', 'annual_entitlement_days', 'submission_deadline_days_before', 'active', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'submission_deadline_days_before' => 'integer',
            'annual_entitlement_days' => 'integer',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function isEffectiveOn(string $date): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->effective_from !== null && $this->effective_from->toDateString() > $date) {
            return false;
        }

        if ($this->effective_to !== null && $this->effective_to->toDateString() < $date) {
            return false;
        }

        return true;
    }
}
