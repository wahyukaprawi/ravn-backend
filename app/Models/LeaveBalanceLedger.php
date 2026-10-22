<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveBalanceLedger extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'leave_balance_ledger';

    protected $fillable = ['user_id', 'leave_type_id', 'leave_request_id', 'entry_type', 'amount', 'reason', 'actor_id', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
