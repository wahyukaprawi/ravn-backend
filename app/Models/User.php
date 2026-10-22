<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const STATUS_PENDING_ACTIVATION = 'pending_activation';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'invitation_expires_at',
        'activated_at',
        'deactivated_at',
        'deactivated_by',
        'last_login_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'invitation_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function canAuthenticate(): bool
    {
        return $this->isActive() && $this->password !== null;
    }

    public function activationTokens(): HasMany
    {
        return $this->hasMany(UserActivationToken::class);
    }

    public function mfaMethods(): HasMany
    {
        return $this->hasMany(UserMfaMethod::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }
}
