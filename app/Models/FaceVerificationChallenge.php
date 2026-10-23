<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaceVerificationChallenge extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'challenge_id',
        'device_id',
        'status',
        'attempts',
        'max_attempts',
        'expires_at',
        'verified_at',
        'failed_at',
        'used_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'failed_at' => 'datetime',
            'used_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->used_at === null
            && $this->attempts < $this->max_attempts
            && $this->expires_at->isFuture();
    }
}
