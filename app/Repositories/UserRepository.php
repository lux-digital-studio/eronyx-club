<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, password_hash, status FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, username
             FROM profiles
             WHERE LOWER(username) = :username
                AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $profile = $statement->fetch();

        return is_array($profile) ? $profile : null;
    }

    public function createUser(string $email, string $passwordHash, string $status = 'active'): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, status) VALUES (:email, :password_hash, :status)'
        );
        $statement->execute([
            'email' => $email,
            'password_hash' => $passwordHash,
            'status' => $status,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateLastLoginAt(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET last_login_at = CURRENT_TIMESTAMP
             WHERE id = :id
                AND deleted_at IS NULL
                AND status = :status'
        );
        $statement->execute([
            'id' => $userId,
            'status' => 'active',
        ]);

        return true;
    }

    public function createProfile(int $userId, string $displayName, string $username): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO profiles (user_id, display_name, username) VALUES (:user_id, :display_name, :username)'
        );
        $statement->execute([
            'user_id' => $userId,
            'display_name' => $displayName,
            'username' => $username,
        ]);
    }

    public function findRoleIdByName(string $name): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
        $statement->execute(['name' => $name]);
        $roleId = $statement->fetchColumn();

        return $roleId === false ? null : (int) $roleId;
    }

    public function assignRole(int $userId, int $roleId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)'
        );
        $statement->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
    }

    /** @return array{id: int, status: string, deleted_at: string|null, roles: list<string>}|null */
    public function findAuthorizationContext(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.status, u.deleted_at, r.name AS role_name
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.id = :id'
        );
        $statement->execute(['id' => $userId]);
        $rows = $statement->fetchAll();

        if ($rows === []) {
            return null;
        }

        $first = $rows[0];
        $roles = [];

        foreach ($rows as $row) {
            if (is_string($row['role_name'] ?? null)) {
                $roles[] = $row['role_name'];
            }
        }

        return [
            'id' => (int) $first['id'],
            'status' => (string) $first['status'],
            'deleted_at' => is_string($first['deleted_at']) ? $first['deleted_at'] : null,
            'roles' => array_values(array_unique($roles)),
        ];
    }
}
