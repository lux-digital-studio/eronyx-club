<?php

declare(strict_types=1);

namespace App\Validators;

final class ChangePasswordValidator
{
    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, data: array{current_password: string, new_password: string}, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $current = (string) ($input['current_password'] ?? '');
        $new = (string) ($input['new_password'] ?? '');
        $confirmation = (string) ($input['new_password_confirmation'] ?? '');
        $errors = [];

        if ($current === '' || strlen($current) > PasswordPolicy::MAX_LENGTH) {
            $errors['current_password'] = 'La contraseña actual no es correcta.';
        }

        $policyError = PasswordPolicy::error($new);

        if ($policyError !== null) {
            $errors['new_password'] = $policyError;
        } elseif ($new !== $confirmation) {
            $errors['new_password_confirmation'] = 'La confirmación de contraseña no coincide.';
        } elseif ($current !== '' && $new === $current) {
            $errors['new_password'] = 'La nueva contraseña debe ser distinta a la actual.';
        }

        return [
            'valid' => $errors === [],
            'data' => [
                'current_password' => $current,
                'new_password' => $new,
            ],
            'errors' => $errors,
        ];
    }
}
