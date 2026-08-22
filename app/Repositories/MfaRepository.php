<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class MfaRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /** @return array{id: int, user_id: int, type: string, status: string, secret_encrypted: string, enabled_at: string|null}|null */
    public function findForUser(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, type, status, secret_encrypted, enabled_at
             FROM user_mfa
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalizeMfa($row) : null;
    }

    public function isEnabled(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM user_mfa
             WHERE user_id = :user_id
                AND status = 'enabled'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function upsertPending(int $userId, string $secretEncrypted): void
    {
        $existing = $this->findForUser($userId);

        if ($existing === null) {
            $statement = $this->pdo->prepare(
                "INSERT INTO user_mfa (user_id, type, status, secret_encrypted, enabled_at)
                 VALUES (:user_id, 'totp', 'pending', :secret_encrypted, NULL)"
            );
            $statement->execute([
                'user_id' => $userId,
                'secret_encrypted' => $secretEncrypted,
            ]);

            return;
        }

        $statement = $this->pdo->prepare(
            "UPDATE user_mfa
             SET type = 'totp',
                 status = 'pending',
                 secret_encrypted = :secret_encrypted,
                 enabled_at = NULL
             WHERE user_id = :user_id
                AND status = 'pending'"
        );
        $statement->execute([
            'user_id' => $userId,
            'secret_encrypted' => $secretEncrypted,
        ]);
    }

    public function enable(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE user_mfa
             SET status = 'enabled',
                 enabled_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id
                AND status = 'pending'"
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->rowCount() === 1;
    }

    public function deleteForUser(int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM user_mfa WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
    }

    /** @param list<string> $hashes */
    public function replaceRecoveryCodes(int $userId, array $hashes): void
    {
        $delete = $this->pdo->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = :user_id');
        $delete->execute(['user_id' => $userId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO mfa_recovery_codes (user_id, code_hash) VALUES (:user_id, :code_hash)'
        );

        foreach ($hashes as $hash) {
            $insert->execute([
                'user_id' => $userId,
                'code_hash' => $hash,
            ]);
        }
    }

    public function consumeRecoveryCode(int $userId, string $codeHash): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE mfa_recovery_codes
             SET used_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id
                AND code_hash = :code_hash
                AND used_at IS NULL'
        );
        $statement->execute([
            'user_id' => $userId,
            'code_hash' => $codeHash,
        ]);

        return $statement->rowCount() === 1;
    }

    public function invalidateRecoveryCodes(int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
    }

    public function unusedRecoveryCount(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM mfa_recovery_codes
             WHERE user_id = :user_id
                AND used_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<string> */
    public function recoveryHashesForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT code_hash
             FROM mfa_recovery_codes
             WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(static fn (array $row): string => (string) $row['code_hash'], $statement->fetchAll());
    }

    /** @param array<string, mixed> $row */
    private function normalizeMfa(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'type' => (string) $row['type'],
            'status' => (string) $row['status'],
            'secret_encrypted' => (string) $row['secret_encrypted'],
            'enabled_at' => is_string($row['enabled_at'] ?? null) ? $row['enabled_at'] : null,
        ];
    }
}
