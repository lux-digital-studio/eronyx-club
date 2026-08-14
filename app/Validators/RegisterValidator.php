<?php

declare(strict_types=1);

namespace App\Validators;

use App\Repositories\UserRepository;

final class RegisterValidator
{
    public function __construct(
        private readonly UserRepository $users
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, data: array<string, string>, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $username = strtolower(trim((string) ($input['username'] ?? '')));
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

        if ($username === '') {
            $errors['username'] = 'El nombre de usuario es obligatorio.';
        } elseif (strlen($username) < 3 || strlen($username) > 50) {
            $errors['username'] = 'El nombre de usuario debe tener entre 3 y 50 caracteres.';
        } elseif (preg_match('/\A[a-z0-9_]+\z/', $username) !== 1) {
            $errors['username'] = 'El nombre de usuario solo puede contener minúsculas, números y guiones bajos.';
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
        } elseif (strlen($password) < 10) {
            $errors['password'] = 'La contraseña debe tener al menos 10 caracteres.';
        } elseif (strlen($password) > 255) {
            $errors['password'] = 'La contraseña no puede superar 255 caracteres.';
        } elseif ($password !== $confirmation) {
            $errors['password_confirmation'] = 'La confirmación de contraseña no coincide.';
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
}
