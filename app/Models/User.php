<?php

namespace App\Models;

use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
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
        'manager_id',
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

    public function mfaRecoveryCodes(): HasMany
    {
        return $this->hasMany(UserMfaRecoveryCode::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isActive() && $this->hasAnyPermission([
            'users.view',
            'roles.view',
            'leave_policies.manage',
            'attendance.manage_schedules',
            'reports.manage_exports',
            'announcements.manage',
            'notifications.view_failures',
            'audit.view',
        ]);
    }

    public function getAppAuthenticationSecret(): ?string
    {
        $method = $this->mfaMethods()
            ->where('type', UserMfaMethod::TYPE_TOTP)
            ->whereNotNull('confirmed_at')
            ->first();

        return $method?->secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        if ($secret === null) {
            DB::transaction(function (): void {
                $this->mfaRecoveryCodes()->delete();
                $this->mfaMethods()->where('type', UserMfaMethod::TYPE_TOTP)->delete();
            });

            return;
        }

        $this->mfaMethods()->updateOrCreate(
            ['type' => UserMfaMethod::TYPE_TOTP],
            [
                'secret' => $secret,
                'confirmed_at' => now(),
                'last_used_at' => now(),
            ],
        );
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        $codes = $this->mfaRecoveryCodes()
            ->whereNull('used_at')
            ->pluck('code_hash')
            ->all();

        return $codes === [] ? null : $codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        DB::transaction(function () use ($codes): void {
            $this->mfaRecoveryCodes()->delete();

            foreach ($codes ?? [] as $code) {
                $this->mfaRecoveryCodes()->create([
                    'code_hash' => $code,
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'notifiable_id')
            ->where('notifiable_type', $this->getMorphClass());
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }
}
