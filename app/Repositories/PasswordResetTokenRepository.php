<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PasswordResetTokenRepository
{
    public const TTL_SECONDS = 1800;

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function create(int $userId, string $tokenHash, string $expiresAt, ?string $requestedIpHash): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, requested_ip_hash)
             VALUES (:user_id, :token_hash, :expires_at, :requested_ip_hash)'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'requested_ip_hash' => $requestedIpHash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function invalidateActiveForUser(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE password_reset_tokens
             SET used_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id
                AND used_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->rowCount();
    }

    /** @return array{id: int, user_id: int, token_hash: string, expires_at: string, used_at: string|null}|null */
    public function lockValidByTokenHash(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, token_hash, expires_at, used_at
             FROM password_reset_tokens
             WHERE token_hash = :token_hash
                AND used_at IS NULL
                AND expires_at > CURRENT_TIMESTAMP
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    public function findValidByTokenHash(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, token_hash, expires_at, used_at
             FROM password_reset_tokens
             WHERE token_hash = :token_hash
                AND used_at IS NULL
                AND expires_at > CURRENT_TIMESTAMP
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    public function consume(int $id, int $userId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE password_reset_tokens
             SET used_at = CURRENT_TIMESTAMP
             WHERE id = :id
                AND user_id = :user_id
                AND used_at IS NULL
                AND expires_at > CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function cleanupExpiredForUser(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM password_reset_tokens
             WHERE user_id = :user_id
                AND (
                    used_at IS NOT NULL
                    OR expires_at <= CURRENT_TIMESTAMP
                )
                AND created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->rowCount();
    }

    /** @param array<string, mixed> $row @return array{id: int, user_id: int, token_hash: string, expires_at: string, used_at: string|null} */
    private function normalize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'token_hash' => (string) $row['token_hash'],
            'expires_at' => (string) $row['expires_at'],
            'used_at' => is_string($row['used_at'] ?? null) ? $row['used_at'] : null,
        ];
    }
}
