<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class CreatorApplicationRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findByUserId(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, status, created_at, updated_at, deleted_at
             FROM creator_profiles
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $application = $statement->fetch();

        return is_array($application) ? $this->normalize($application) : null;
    }

    /** @return array<string, mixed>|null */
    public function findPendingById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT cp.id, cp.user_id, cp.status, cp.created_at, cp.updated_at, cp.deleted_at,
                    p.display_name, p.username,
                    av.id AS age_verification_id, av.status AS age_status, av.method AS age_method, av.created_at AS age_created_at
             FROM creator_profiles cp
             INNER JOIN profiles p ON p.user_id = cp.user_id
             LEFT JOIN age_verifications av ON av.id = (
                SELECT av2.id
                FROM age_verifications av2
                WHERE av2.user_id = cp.user_id
                   AND av2.status = 'pending'
                   AND av2.method = 'self_declaration'
                ORDER BY av2.id DESC
                LIMIT 1
             )
             WHERE cp.id = :id
                AND cp.status = 'pending'
                AND cp.deleted_at IS NULL
             LIMIT 1"
        );
        $statement->execute(['id' => $id]);
        $application = $statement->fetch();

        return is_array($application) ? $this->normalize($application) : null;
    }

    /** @return list<array<string, mixed>> */
    public function findPendingApplications(): array
    {
        $statement = $this->pdo->query(
            "SELECT cp.id, cp.user_id, cp.status, cp.created_at, cp.updated_at,
                    p.display_name, p.username,
                    av.status AS age_status, av.method AS age_method
             FROM creator_profiles cp
             INNER JOIN profiles p ON p.user_id = cp.user_id
             LEFT JOIN age_verifications av ON av.id = (
                SELECT av2.id
                FROM age_verifications av2
                WHERE av2.user_id = cp.user_id
                   AND av2.status = 'pending'
                   AND av2.method = 'self_declaration'
                ORDER BY av2.id DESC
                LIMIT 1
             )
             WHERE cp.status = 'pending'
                AND cp.deleted_at IS NULL
             ORDER BY cp.created_at ASC, cp.id ASC"
        );

        return array_map(fn (array $application): array => $this->normalize($application), $statement->fetchAll());
    }

    public function createPendingApplication(int $userId): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO creator_profiles (user_id, status)
             VALUES (:user_id, 'pending')"
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function reapply(int $applicationId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE creator_profiles
             SET status = 'pending'
             WHERE id = :id
                AND status = 'rejected'
                AND deleted_at IS NULL"
        );
        $statement->execute(['id' => $applicationId]);

        return $statement->rowCount() === 1;
    }

    public function createPendingAgeVerification(int $userId): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO age_verifications (
                user_id, status, method, provider, provider_reference, verified_at, expires_at
             ) VALUES (
                :user_id, 'pending', 'self_declaration', NULL, NULL, NULL, NULL
             )"
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function hasPendingSelfDeclaration(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM age_verifications
             WHERE user_id = :user_id
                AND status = 'pending'
                AND method = 'self_declaration'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function approveApplication(int $applicationId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE creator_profiles
             SET status = 'active'
             WHERE id = :id
                AND status = 'pending'
                AND deleted_at IS NULL"
        );
        $statement->execute(['id' => $applicationId]);

        return $statement->rowCount() === 1;
    }

    public function rejectApplication(int $applicationId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE creator_profiles
             SET status = 'rejected'
             WHERE id = :id
                AND status = 'pending'
                AND deleted_at IS NULL"
        );
        $statement->execute(['id' => $applicationId]);

        return $statement->rowCount() === 1;
    }

    public function verifyPendingAgeDeclaration(int $userId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE age_verifications
             SET status = 'verified',
                 verified_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id
                AND status = 'pending'
                AND method = 'self_declaration'
             ORDER BY id DESC
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);
    }

    public function rejectPendingAgeDeclaration(int $userId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE age_verifications
             SET status = 'rejected',
                 verified_at = NULL
             WHERE user_id = :user_id
                AND status = 'pending'
                AND method = 'self_declaration'
             ORDER BY id DESC
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);
    }

    public function assignCreatorRole(int $userId): void
    {
        $roleId = $this->creatorRoleId();

        if ($roleId === null) {
            throw new \RuntimeException('creator_role_missing');
        }

        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)'
        );
        $statement->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
    }

    public function hasCreatorRole(int $userId): bool
    {
        $roleId = $this->creatorRoleId();

        if ($roleId === null) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'SELECT 1 FROM user_roles WHERE user_id = :user_id AND role_id = :role_id LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array<string, mixed>|null */
    public function findActiveUser(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, status, deleted_at
             FROM users
             WHERE id = :id
                AND status = 'active'
                AND deleted_at IS NULL
             LIMIT 1"
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();

        return is_array($user) ? $this->normalize($user) : null;
    }

    private function creatorRoleId(): ?int
    {
        $statement = $this->pdo->prepare("SELECT id FROM roles WHERE name = 'creator' LIMIT 2");
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);

        return count($rows) === 1 ? (int) $rows[0] : null;
    }

    /** @param array<string, mixed> $application @return array<string, mixed> */
    private function normalize(array $application): array
    {
        foreach (['id', 'user_id', 'age_verification_id'] as $key) {
            if (array_key_exists($key, $application) && $application[$key] !== null) {
                $application[$key] = (int) $application[$key];
            }
        }

        return $application;
    }
}
