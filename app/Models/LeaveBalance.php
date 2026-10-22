<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $fillable = ['user_id', 'leave_type_id', 'year', 'entitled', 'used', 'reserved', 'adjusted'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'entitled' => 'decimal:2',
            'used' => 'decimal:2',
            'reserved' => 'decimal:2',
            'adjusted' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function available(): float
    {
        return (float) $this->entitled + (float) $this->adjusted - (float) $this->used - (float) $this->reserved;
    }
}
