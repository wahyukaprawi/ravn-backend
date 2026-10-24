<?php

namespace App\Http\Responses;

use App\Models\Employee;
use App\Models\User;

class UserSessionPayload
{
    public static function from(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'employee_id' => Employee::query()->where('user_id', $user->id)->value('id'),
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
