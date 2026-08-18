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

        $this->loginUserId($userId);

        return $userId;
    }

    public function attempt(string $email, string $password): bool
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || $user['status'] !== 'active') {
            return false;
        }

        if (!is_string($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        $userId = (int) $user['id'];

        try {
            $this->loginUserId($userId);
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

    private function loginUserId(int $userId): void
    {
        $this->session->regenerate();
        $this->session->put('auth_user_id', $userId);
    }
}
