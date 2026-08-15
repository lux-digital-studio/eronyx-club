<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PrivateContentAccessRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findValidGrant(int $userId, int $listingId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, user_id, listing_id, granted_by_user_id, source, status, granted_at, expires_at, revoked_at, created_at, updated_at
             FROM private_content_access
             WHERE user_id = :user_id
                AND listing_id = :listing_id
                AND status = 'active'
                AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
             ORDER BY id ASC
             LIMIT 1"
        );
        $statement->execute([
            'user_id' => $userId,
            'listing_id' => $listingId,
        ]);
        $grant = $statement->fetch();

        return is_array($grant) ? $this->normalize($grant) : null;
    }

    public function hasValidGrant(int $userId, int $listingId): bool
    {
        return $this->findValidGrant($userId, $listingId) !== null;
    }

    /** @return array<string, mixed> */
    public function createGrant(int $userId, int $listingId, ?int $grantedByUserId, string $source, ?string $expiresAt = null): array
    {
        $existing = $this->findValidGrant($userId, $listingId);

        if ($existing !== null) {
            return $existing;
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO private_content_access (
                user_id, listing_id, granted_by_user_id, source, status,
                granted_at, expires_at, revoked_at, created_at, updated_at
             ) VALUES (
                :user_id, :listing_id, :granted_by_user_id, :source, 'active',
                CURRENT_TIMESTAMP, :expires_at, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )"
        );
        $statement->execute([
            'user_id' => $userId,
            'listing_id' => $listingId,
            'granted_by_user_id' => $grantedByUserId,
            'source' => $source,
            'expires_at' => $expiresAt,
        ]);

        $grant = $this->findById((int) $this->pdo->lastInsertId());

        if ($grant === null) {
            throw new \RuntimeException('No se pudo crear el acceso privado.');
        }

        return $grant;
    }

    public function revokeGrant(int $userId, int $listingId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE private_content_access
             SET status = 'revoked',
                 revoked_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id
                AND listing_id = :listing_id
                AND status = 'active'
                AND revoked_at IS NULL"
        );
        $statement->execute([
            'user_id' => $userId,
            'listing_id' => $listingId,
        ]);

        return $statement->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function findByListing(int $listingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, listing_id, granted_by_user_id, source, status, granted_at, expires_at, revoked_at, created_at, updated_at
             FROM private_content_access
             WHERE listing_id = :listing_id
             ORDER BY id ASC'
        );
        $statement->execute(['listing_id' => $listingId]);

        return array_map(fn (array $grant): array => $this->normalize($grant), $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    private function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, listing_id, granted_by_user_id, source, status, granted_at, expires_at, revoked_at, created_at, updated_at
             FROM private_content_access
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $grant = $statement->fetch();

        return is_array($grant) ? $this->normalize($grant) : null;
    }

    /** @param array<string, mixed> $grant @return array<string, mixed> */
    private function normalize(array $grant): array
    {
        foreach (['id', 'user_id', 'listing_id', 'granted_by_user_id'] as $key) {
            if (array_key_exists($key, $grant) && $grant[$key] !== null) {
                $grant[$key] = (int) $grant[$key];
            }
        }

        return $grant;
    }
}
