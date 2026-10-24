<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAdministrationService
{
    public const LAST_SUPER_ADMIN_MESSAGE = 'The last active Super Admin cannot be deactivated or demoted.';

    public const SELF_DEACTIVATION_MESSAGE = 'You cannot deactivate your own account.';

    public const SELF_DEMOTION_MESSAGE = 'You cannot remove your own Super Admin access.';

    public function __construct(
        private readonly ActivationTokenService $activationTokens,
        private readonly SessionService $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    public function invite(User $actor, array $attributes): array
    {
        $email = mb_strtolower(trim((string) $attributes['email']));

        return DB::transaction(function () use ($actor, $attributes, $email) {
            try {
                $user = User::query()->create([
                    'name' => $attributes['name'],
                    'email' => $email,
                    'password' => null,
                    'status' => User::STATUS_PENDING_ACTIVATION,
                    'created_by' => $actor->getKey(),
                ]);
            } catch (QueryException $exception) {
                $this->rethrowDuplicateEmail($exception);
            }

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
        if (array_key_exists('email', $attributes)) {
            $attributes['email'] = mb_strtolower(trim((string) $attributes['email']));
        }

        return DB::transaction(function () use ($actor, $user, $attributes) {
            $before = [
                'name' => $user->name,
                'email' => $user->email,
            ];

            if (array_key_exists('email', $attributes) && $attributes['email'] !== $user->email) {
                $this->activationTokens->invalidateFor($user);
            }

            try {
                $user->fill(array_intersect_key($attributes, array_flip(['name', 'email'])))->save();
            } catch (QueryException $exception) {
                $this->rethrowDuplicateEmail($exception);
            }

            $this->audit->record('user.update', $user, $before, [
                'name' => $user->name,
                'email' => $user->email,
            ], $actor);

            return $user->refresh();
        });
    }

    public function deactivate(User $actor, User $user): User
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['user' => self::SELF_DEACTIVATION_MESSAGE]);
        }

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
            if ($actor->is($user) && $actor->hasRole('super_admin') && ! in_array('super_admin', $roles, true)) {
                throw ValidationException::withMessages(['roles' => self::SELF_DEMOTION_MESSAGE]);
            }

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

    protected function rethrowDuplicateEmail(QueryException $exception): never
    {
        $duplicate = ($exception->errorInfo[1] ?? null) === 1062
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed: users.email')
            || str_contains($exception->getMessage(), 'users_email_unique');

        if ($duplicate) {
            throw ValidationException::withMessages([
                'email' => 'The email has already been taken.',
            ]);
        }

        throw $exception;
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
