<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaceVerificationChallenge extends Model
{
    protected $fillable = ['user_id', 'challenge_id', 'status', 'attempts', 'max_attempts', 'expires_at', 'verified_at', 'failed_at', 'metadata'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return $this->status === 'pending' && $this->attempts < $this->max_attempts && $this->expires_at->isFuture();
    }
}
