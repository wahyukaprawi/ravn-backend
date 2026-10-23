<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAssignment extends Model
{
    protected $fillable = [
        'employee_id',
        'employee_employment_id',
        'department_id',
        'position_id',
        'manager_id',
        'work_location_id',
        'effective_from',
        'effective_to',
        'replaced_assignment_id',
        'canceled_at',
        'canceled_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'canceled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function employment(): BelongsTo
    {
        return $this->belongsTo(EmployeeEmployment::class, 'employee_employment_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function canceledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canceled_by');
    }

    public function status(): string
    {
        if ($this->canceled_at !== null) {
            return 'canceled';
        }

        $today = now()->toDateString();
        $from = $this->effective_from->toDateString();
        $to = $this->effective_to?->toDateString();

        if ($from > $today) {
            return 'future';
        }

        if ($to !== null && $to < $today) {
            return 'past';
        }

        return 'active';
    }
}
