<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMfaRecoveryCode extends Model
{
    protected $fillable = [
        'user_id',
        'code_hash',
        'used_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
