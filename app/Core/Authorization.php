<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\UserRepository;

final class Authorization
{
    /** @var array{id: int, status: string, deleted_at: string|null, roles: list<string>}|null */
    private ?array $context = null;
    private bool $loaded = false;

    public function __construct(
        private readonly Auth $auth,
        private readonly UserRepository $users
    ) {
    }

    /** @return list<string> */
    public function roles(): array
    {
        $context = $this->context();

        return $context['roles'] ?? [];
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    /** @param list<string> $roles */
    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function isActive(): bool
    {
        $context = $this->context();

        return $context !== null
            && $context['status'] === 'active'
            && $context['deleted_at'] === null;
    }

    /** @return array{id: int, status: string, deleted_at: string|null, roles: list<string>}|null */
    private function context(): ?array
    {
        if ($this->loaded) {
            return $this->context;
        }

        $this->loaded = true;
        $userId = $this->auth->id();

        if ($userId === null) {
            return null;
        }

        $this->context = $this->users->findAuthorizationContext($userId);

        return $this->context;
    }
}
