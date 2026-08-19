<?php

declare(strict_types=1);

namespace App\Validators;

final class ResetPasswordValidator
{
    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, data: array{new_password: string}, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $new = (string) ($input['new_password'] ?? '');
        $confirmation = (string) ($input['new_password_confirmation'] ?? '');
        $errors = [];
        $policyError = PasswordPolicy::error($new);

        if ($policyError !== null) {
            $errors['new_password'] = $policyError;
        } elseif ($new !== $confirmation) {
            $errors['new_password_confirmation'] = 'La confirmación de contraseña no coincide.';
        }

        return [
            'valid' => $errors === [],
            'data' => [
                'new_password' => $new,
            ],
            'errors' => $errors,
        ];
    }
}
