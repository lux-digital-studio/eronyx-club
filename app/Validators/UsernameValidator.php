<?php

declare(strict_types=1);

namespace App\Validators;

final class UsernameValidator
{
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 50;

    private const RESERVED_USERNAMES = [
        'admin',
        'administrator',
        'moderator',
        'creator',
        'creators',
        'marketplace',
        'account',
        'accounts',
        'login',
        'register',
        'logout',
        'checkout',
        'media',
        'api',
        'support',
        'help',
        'terms',
        'privacy',
        'eronyx',
        'listings',
        'listing',
    ];

    public function normalize(string $username): string
    {
        return strtolower(trim($username));
    }

    /**
     * @return array{valid: bool, username: string, error: string|null}
     */
    public function validate(string $raw): array
    {
        $username = $this->normalize($raw);

        if ($username === '') {
            return [
                'valid' => false,
                'username' => $username,
                'error' => 'El nombre de usuario es obligatorio.',
            ];
        }

        if (strlen($username) < self::MIN_LENGTH || strlen($username) > self::MAX_LENGTH) {
            return [
                'valid' => false,
                'username' => $username,
                'error' => 'El nombre de usuario debe tener entre 3 y 50 caracteres.',
            ];
        }

        if (preg_match('/\A[a-z0-9_-]+\z/', $username) !== 1) {
            return [
                'valid' => false,
                'username' => $username,
                'error' => 'El nombre de usuario solo puede contener minúsculas, números, guion y guion bajo.',
            ];
        }

        if (in_array($username, self::RESERVED_USERNAMES, true)) {
            return [
                'valid' => false,
                'username' => $username,
                'error' => 'Este nombre de usuario está reservado.',
            ];
        }

        return [
            'valid' => true,
            'username' => $username,
            'error' => null,
        ];
    }

    /** @return list<string> */
    public function reservedUsernames(): array
    {
        return self::RESERVED_USERNAMES;
    }
}
