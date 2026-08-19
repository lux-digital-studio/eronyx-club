<?php

declare(strict_types=1);

namespace App\Validators;

final class PasswordPolicy
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 255;

    public static function error(string $password): ?string
    {
        if ($password === '') {
            return 'La contraseña es obligatoria.';
        }

        $length = strlen($password);

        if ($length < self::MIN_LENGTH) {
            return 'La contraseña debe tener al menos 10 caracteres.';
        }

        if ($length > self::MAX_LENGTH) {
            return 'La contraseña no puede superar 255 caracteres.';
        }

        return null;
    }
}
