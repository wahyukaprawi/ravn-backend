<?php

namespace App\Filament\Concerns;

trait AuthorizesWithPermissions
{
    public static function canViewAny(): bool
    {
        return static::hasFilamentPermission(static::$viewAnyPermission);
    }

    public static function canView($record): bool
    {
        return static::hasFilamentPermission(static::$viewPermission ?? static::$viewAnyPermission);
    }

    public static function canCreate(): bool
    {
        return static::hasFilamentPermission(static::$createPermission);
    }

    public static function canEdit($record): bool
    {
        return static::hasFilamentPermission(static::$updatePermission);
    }

    public static function canDelete($record): bool
    {
        return static::hasFilamentPermission(static::$deletePermission);
    }

    public static function canDeleteAny(): bool
    {
        return static::hasFilamentPermission(static::$deletePermission);
    }

    public static function canRestore($record): bool
    {
        return static::hasFilamentPermission(static::$deletePermission);
    }

    public static function canRestoreAny(): bool
    {
        return static::hasFilamentPermission(static::$deletePermission);
    }

    public static function canForceDelete($record): bool
    {
        return static::hasFilamentPermission(static::$deletePermission);
    }

    public static function canForceDeleteAny(): bool
    {
        return static::hasFilamentPermission(static::$deletePermission);
    }

    protected static function hasFilamentPermission(?string $permission): bool
    {
        if ($permission === null) {
            return false;
        }

        return auth()->user()?->can($permission) ?? false;
    }
}
