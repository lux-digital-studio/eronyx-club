<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;
use App\Repositories\UserRepository;
use RuntimeException;
use Throwable;

final class AuthService
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$EBVKylk7vGJdYIgg29F4SOO4OfEyR6RJkWv12k3Nm81l8h4yjPotS';

    private \PDO $pdo;
    private UserRepository $users;

    public function __construct(
        private readonly Session $session,
        ?\PDO $pdo = null,
        ?UserRepository $users = null
    ) {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->users = $users ?? new UserRepository($this->pdo);
    }

    /** @param array{email: string, username: string, display_name: string, password: string} $data */
    public function register(array $data): int
    {
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            throw new RuntimeException('Unable to securely hash password.');
        }

        try {
            $this->pdo->beginTransaction();

            $userId = $this->users->createUser($data['email'], $passwordHash, 'active');
            $this->users->createProfile($userId, $data['display_name'], $data['username']);

            $buyerRoleId = $this->users->findRoleIdByName('buyer');

            if ($buyerRoleId === null) {
                throw new RuntimeException('Required buyer role is missing.');
            }

            $this->users->assignRole($userId, $buyerRoleId);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $this->loginUserId($userId, 1);

        return $userId;
    }

    public function attempt(string $email, string $password): bool
    {
        $user = $this->users->findByEmail($email);
        $hash = is_array($user) && is_string($user['password_hash'] ?? null)
            ? $user['password_hash']
            : self::DUMMY_PASSWORD_HASH;
        $verified = password_verify($password, $hash);

        if (!is_array($user) || $user['status'] !== 'active' || !$verified) {
            return false;
        }

        $userId = (int) $user['id'];

        try {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $rehashed = password_hash($password, PASSWORD_DEFAULT);

                if (is_string($rehashed)) {
                    $this->users->updatePasswordHash($userId, $rehashed);
                }
            }

            $this->loginUserId($userId, max(1, (int) ($user['session_version'] ?? 1)));
            $this->users->updateLastLoginAt($userId);
        } catch (Throwable) {
            $this->logout();

            return false;
        }

        return true;
    }

    public function logout(): void
    {
        $this->session->remove('auth_user_id');
        $this->session->invalidate();
    }

    private function loginUserId(int $userId, ?int $sessionVersion = null): void
    {
        $version = $sessionVersion ?? $this->users->sessionVersion($userId);
        $this->session->regenerate();
        $this->session->put('auth_user_id', $userId);
        $this->session->put('auth_session_version', $version);
    }
}
