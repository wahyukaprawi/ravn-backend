<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAdministrationService
{
    public const LAST_SUPER_ADMIN_MESSAGE = 'The last active Super Admin cannot be deactivated or demoted.';

    public function __construct(
        private readonly ActivationTokenService $activationTokens,
        private readonly SessionService $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    public function invite(User $actor, array $attributes): array
    {
        return DB::transaction(function () use ($actor, $attributes) {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => null,
                'status' => User::STATUS_PENDING_ACTIVATION,
                'created_by' => $actor->getKey(),
            ]);

            $issued = $this->activationTokens->issue($user);

            $this->audit->record(
                'user.invite',
                $user,
                null,
                ['email' => $user->email, 'status' => $user->status],
                $actor,
            );

            return [$user, $issued];
        });
    }

    public function update(User $actor, User $user, array $attributes): User
    {
        return DB::transaction(function () use ($actor, $user, $attributes) {
            $before = [
                'name' => $user->name,
                'email' => $user->email,
            ];

            if (array_key_exists('email', $attributes) && $attributes['email'] !== $user->email) {
                $this->activationTokens->invalidateFor($user);
            }

            $user->fill(array_intersect_key($attributes, array_flip(['name', 'email'])))->save();

            $this->audit->record('user.update', $user, $before, [
                'name' => $user->name,
                'email' => $user->email,
            ], $actor);

            return $user->refresh();
        });
    }

    public function deactivate(User $actor, User $user): User
    {
        return DB::transaction(function () use ($actor, $user) {
            $this->guardLastSuperAdmin($user);

            $user->forceFill([
                'status' => User::STATUS_INACTIVE,
                'deactivated_at' => now(),
                'deactivated_by' => $actor->getKey(),
            ])->save();

            $this->sessions->revokeAll($user);
            $this->activationTokens->invalidateFor($user);

            $this->audit->record('user.deactivate', $user, ['status' => User::STATUS_ACTIVE], ['status' => User::STATUS_INACTIVE], $actor);

            return $user->refresh();
        });
    }

    public function reactivate(User $actor, User $user): User
    {
        return DB::transaction(function () use ($actor, $user) {
            $before = ['status' => $user->status];

            $user->forceFill([
                'status' => User::STATUS_ACTIVE,
                'deactivated_at' => null,
                'deactivated_by' => null,
            ])->save();

            $this->audit->record('user.reactivate', $user, $before, ['status' => User::STATUS_ACTIVE], $actor);

            return $user->refresh();
        });
    }

    public function revokeSessions(User $actor, User $user): int
    {
        return DB::transaction(function () use ($actor, $user) {
            $revoked = $this->sessions->revokeAll($user);

            $this->audit->record('user.revoke_sessions', $user, null, ['revoked' => $revoked], $actor);

            return $revoked;
        });
    }

    public function reissueInvitation(User $actor, User $user): string
    {
        return DB::transaction(function () use ($actor, $user) {
            if ($user->status !== User::STATUS_PENDING_ACTIVATION) {
                throw ValidationException::withMessages([
                    'status' => 'Only an account awaiting activation can have its invitation reissued.',
                ]);
            }

            $issued = $this->activationTokens->issue($user);

            $this->audit->record('user.reissue_invitation', $user, null, null, $actor);

            return $issued;
        });
    }

    public function assignRoles(User $actor, User $user, array $roles): User
    {
        return DB::transaction(function () use ($actor, $user, $roles) {
            $this->guardSuperAdminGrant($actor, $roles);
            $this->guardLastSuperAdminDemotion($user, $roles);

            $before = $user->getRoleNames()->values()->all();

            $user->syncRoles($roles);

            $this->sessions->revokeAll($user);

            $this->audit->record(
                'user.assign_roles',
                $user,
                ['roles' => $before],
                ['roles' => $user->getRoleNames()->values()->all()],
                $actor,
            );

            return $user->refresh();
        });
    }

    protected function guardLastSuperAdmin(User $user): void
    {
        if (! $user->hasRole('super_admin') || ! $user->isActive()) {
            return;
        }

        $lock = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->lockForUpdate()
            ->pluck('id');

        if ($lock->count() <= 1) {
            throw ValidationException::withMessages(['roles' => self::LAST_SUPER_ADMIN_MESSAGE]);
        }
    }

    protected function guardSuperAdminGrant(User $actor, array $roles): void
    {
        if (! in_array('super_admin', $roles, true)) {
            return;
        }

        if ($actor->hasRole('super_admin')) {
            return;
        }

        throw ValidationException::withMessages([
            'roles' => 'Only a Super Admin can grant the super_admin role.',
        ]);
    }

    protected function guardLastSuperAdminDemotion(User $user, array $roles): void
    {
        if (! $user->hasRole('super_admin') || in_array('super_admin', $roles, true)) {
            return;
        }

        $lock = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->lockForUpdate()
            ->pluck('id');

        if ($lock->count() <= 1) {
            throw ValidationException::withMessages(['roles' => self::LAST_SUPER_ADMIN_MESSAGE]);
        }
    }
}
