<?php

declare(strict_types=1);

namespace App\Validators;

final class LoginValidator
{
    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, data: array<string, string>, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $errors = [];

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            $errors['auth'] = 'Email o contraseña incorrectos.';
        }

        if ($password === '') {
            $errors['auth'] = 'Email o contraseña incorrectos.';
        }

        return [
            'valid' => $errors === [],
            'data' => [
                'email' => $email,
                'password' => $password,
            ],
            'errors' => $errors,
        ];
    }
}
