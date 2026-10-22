<?php

namespace App\Support;

class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public function rules(): array
    {
        return ['required', 'string', 'min:'.self::MIN_LENGTH, 'confirmed'];
    }

    public function passes(string $password): bool
    {
        return mb_strlen($password) >= self::MIN_LENGTH;
    }
}
