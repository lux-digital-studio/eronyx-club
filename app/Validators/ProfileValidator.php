<?php

declare(strict_types=1);

namespace App\Validators;

final class ProfileValidator
{
    public function __construct(
        private readonly UsernameValidator $usernames = new UsernameValidator()
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, errors: array<string, string>, data: array{display_name: string, username: string, bio: string|null}}
     */
    public function validate(array $input): array
    {
        $displayName = trim((string) ($input['display_name'] ?? ''));
        $usernameCheck = $this->usernames->validate((string) ($input['username'] ?? ''));
        $username = $usernameCheck['username'];
        $bio = trim((string) ($input['bio'] ?? ''));
        $errors = [];

        if ($displayName === '') {
            $errors['display_name'] = 'El nombre público es obligatorio.';
        } elseif ($this->length($displayName) > 100) {
            $errors['display_name'] = 'El nombre público no puede superar 100 caracteres.';
        }

        if (!$usernameCheck['valid']) {
            $errors['username'] = (string) $usernameCheck['error'];
        }

        if ($bio !== '' && $this->length($bio) > 1000) {
            $errors['bio'] = 'La bio no puede superar 1000 caracteres.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'data' => [
                'display_name' => $displayName,
                'username' => $username,
                'bio' => $bio === '' ? null : $bio,
            ],
        ];
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
