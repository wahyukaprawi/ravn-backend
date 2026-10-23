<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeEmployment extends Model
{
    public const TYPE_PERMANENT = 'permanent';

    public const TYPE_FIXED_TERM = 'fixed_term';

    public const TYPE_INTERNSHIP = 'internship';

    public const TYPES = [
        self::TYPE_PERMANENT,
        self::TYPE_FIXED_TERM,
        self::TYPE_INTERNSHIP,
    ];

    protected $fillable = [
        'employee_id',
        'employment_type',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    public function isEffective(): bool
    {
        $today = now()->toDateString();

        return $this->effective_from->toDateString() <= $today
            && ($this->effective_to === null || $this->effective_to->toDateString() >= $today);
    }

    public function isFuture(): bool
    {
        return $this->effective_from->toDateString() > now()->toDateString();
    }
}
