<?php

declare(strict_types=1);

namespace App\Validators;

use App\Repositories\UserRepository;

final class RegisterValidator
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UsernameValidator $usernames = new UsernameValidator()
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, data: array<string, string>, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $usernameCheck = $this->usernames->validate((string) ($input['username'] ?? ''));
        $username = $usernameCheck['username'];
        $displayName = trim((string) ($input['display_name'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $confirmation = (string) ($input['password_confirmation'] ?? '');

        $errors = [];

        if ($email === '') {
            $errors['email'] = 'El email es obligatorio.';
        } elseif (strlen($email) > 255) {
            $errors['email'] = 'El email no puede superar 255 caracteres.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'El email no es válido.';
        } elseif ($this->users->findByEmail($email) !== null) {
            $errors['email'] = 'Este email ya está registrado.';
        }

        if (!$usernameCheck['valid']) {
            $errors['username'] = (string) $usernameCheck['error'];
        } elseif ($this->users->findByUsername($username) !== null) {
            $errors['username'] = 'Este nombre de usuario no está disponible.';
        }

        if ($displayName === '') {
            $errors['display_name'] = 'El nombre público es obligatorio.';
        } elseif ($this->length($displayName) > 100) {
            $errors['display_name'] = 'El nombre público no puede superar 100 caracteres.';
        }

        if ($password === '') {
            $errors['password'] = 'La contraseña es obligatoria.';
        } else {
            $policyError = PasswordPolicy::error($password);

            if ($policyError !== null) {
                $errors['password'] = $policyError;
            } elseif ($password !== $confirmation) {
                $errors['password_confirmation'] = 'La confirmación de contraseña no coincide.';
            }
        }

        if (!$this->accepted($input['accept_terms'] ?? null)) {
            $errors['accept_terms'] = 'Debes aceptar los términos de uso.';
        }

        if (!$this->accepted($input['accept_privacy'] ?? null)) {
            $errors['accept_privacy'] = 'Debes aceptar la política de privacidad.';
        }

        if (!$this->accepted($input['accept_age'] ?? null)) {
            $errors['accept_age'] = 'Debes declarar que eres mayor de 18 años.';
        }

        return [
            'valid' => $errors === [],
            'data' => [
                'email' => $email,
                'username' => $username,
                'display_name' => $displayName,
                'password' => $password,
            ],
            'errors' => $errors,
        ];
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function accepted(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'on', 'true'], true);
    }
}
