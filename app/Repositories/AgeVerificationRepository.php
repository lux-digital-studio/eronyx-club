<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AgeVerificationRepository
{
    public const STATUSES = ['pending', 'verified', 'rejected', 'expired', 'cancelled'];
    public const METHODS = ['self_declaration', 'manual_review', 'provider'];
    public const REJECTION_CODES = [
        'age_not_confirmed',
        'verification_failed',
        'verification_expired',
        'provider_error',
        'policy_restriction',
        'other',
    ];
    public const METADATA_KEYS = ['source'];

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createVerification(int $userId, array $data): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO age_verifications (
                user_id, status, method, provider, provider_reference, provider_status,
                provider_session_expires_at, reviewed_by_user_id, reviewed_at, rejection_code,
                metadata_json, verified_at, expires_at
             ) VALUES (
                :user_id, :status, :method, :provider, :provider_reference, :provider_status,
                :provider_session_expires_at, NULL, NULL, NULL,
                :metadata_json, NULL, :expires_at
             )"
        );
        $statement->execute([
            'user_id' => $userId,
            'status' => $this->status((string) ($data['status'] ?? 'pending')),
            'method' => $this->method((string) ($data['method'] ?? 'manual_review')),
            'provider' => $this->nullableString($data['provider'] ?? null, 100),
            'provider_reference' => $this->nullableString($data['provider_reference'] ?? null, 255),
            'provider_status' => $this->nullableString($data['provider_status'] ?? null, 40),
            'provider_session_expires_at' => $this->nullableString($data['provider_session_expires_at'] ?? null, 32),
            'metadata_json' => $this->encodeMetadata(is_array($data['metadata'] ?? null) ? $data['metadata'] : []),
            'expires_at' => $this->nullableString($data['expires_at'] ?? null, 32),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findCurrentForUser(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, status, method, provider, provider_status, provider_session_expires_at,
                    reviewed_by_user_id, reviewed_at, rejection_code, verified_at, expires_at,
                    created_at, updated_at
             FROM age_verifications
             WHERE user_id = :user_id
             ORDER BY CASE WHEN status = \'pending\' THEN 0 ELSE 1 END, id DESC
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, status, method, provider, provider_reference, provider_status,
                    provider_session_expires_at, reviewed_by_user_id, reviewed_at, rejection_code,
                    metadata_json, verified_at, expires_at, created_at, updated_at
             FROM age_verifications
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row, true) : null;
    }

    /** @return list<array<string, mixed>> */
    public function findHistoryForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, status, method, provider, provider_status, provider_session_expires_at,
                    reviewed_by_user_id, reviewed_at, rejection_code, verified_at, expires_at,
                    created_at, updated_at
             FROM age_verifications
             WHERE user_id = :user_id
             ORDER BY id DESC'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(fn (array $row): array => $this->normalize($row), $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findPendingForUser(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, user_id, status, method, provider, provider_reference, provider_status,
                    provider_session_expires_at, reviewed_by_user_id, reviewed_at, rejection_code,
                    metadata_json, verified_at, expires_at, created_at, updated_at
             FROM age_verifications
             WHERE user_id = :user_id
                AND status = 'pending'
             ORDER BY id DESC
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row, true) : null;
    }

    public function hasPending(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM age_verifications
             WHERE user_id = :user_id AND status = 'pending'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function hasValidVerification(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM age_verifications
             WHERE user_id = :user_id
                AND status = 'verified'
                AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function markVerified(int $id, ?int $reviewedByUserId = null, ?string $providerStatus = 'verified'): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE age_verifications
             SET status = 'verified',
                 verified_at = CURRENT_TIMESTAMP,
                 reviewed_at = CURRENT_TIMESTAMP,
                 reviewed_by_user_id = :reviewed_by_user_id,
                 provider_status = :provider_status,
                 rejection_code = NULL
             WHERE id = :id
                AND status = 'pending'"
        );
        $statement->execute([
            'id' => $id,
            'reviewed_by_user_id' => $reviewedByUserId,
            'provider_status' => $this->nullableString($providerStatus, 40),
        ]);

        return $statement->rowCount() === 1;
    }

    public function markRejected(int $id, string $rejectionCode, ?int $reviewedByUserId = null, ?string $providerStatus = 'rejected'): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE age_verifications
             SET status = 'rejected',
                 verified_at = NULL,
                 reviewed_at = CURRENT_TIMESTAMP,
                 reviewed_by_user_id = :reviewed_by_user_id,
                 rejection_code = :rejection_code,
                 provider_status = :provider_status
             WHERE id = :id
                AND status = 'pending'"
        );
        $statement->execute([
            'id' => $id,
            'reviewed_by_user_id' => $reviewedByUserId,
            'rejection_code' => $this->rejectionCode($rejectionCode),
            'provider_status' => $this->nullableString($providerStatus, 40),
        ]);

        return $statement->rowCount() === 1;
    }

    public function markExpired(int $id): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE age_verifications
             SET status = 'expired',
                 provider_status = 'expired',
                 rejection_code = 'verification_expired'
             WHERE id = :id
                AND status = 'pending'"
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function markCancelled(int $id, string $rejectionCode = 'policy_restriction'): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE age_verifications
             SET status = 'cancelled',
                 rejection_code = :rejection_code,
                 reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :id
                AND status = 'pending'"
        );
        $statement->execute([
            'id' => $id,
            'rejection_code' => $this->rejectionCode($rejectionCode),
        ]);

        return $statement->rowCount() === 1;
    }

    /** @return list<int> */
    public function expireDuePendingForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id
             FROM age_verifications
             WHERE user_id = :user_id
                AND status = 'pending'
                AND provider_session_expires_at IS NOT NULL
                AND provider_session_expires_at < CURRENT_TIMESTAMP"
        );
        $statement->execute(['user_id' => $userId]);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $statement->fetchAll());

        foreach ($ids as $id) {
            $this->markExpired($id);
        }

        return $ids;
    }

    /** @return array<string, mixed>|null */
    public function adminSummaryForUser(int $userId): ?array
    {
        $row = $this->findCurrentForUser($userId);

        if ($row === null) {
            return null;
        }

        return [
            'id' => $row['id'],
            'status' => $row['status'],
            'method' => $row['method'],
            'provider' => $row['provider'],
            'reviewed_at' => $row['reviewed_at'],
            'expires_at' => $row['expires_at'],
            'verified_at' => $row['verified_at'],
        ];
    }

    private function status(string $status): string
    {
        return in_array($status, self::STATUSES, true) ? $status : 'pending';
    }

    private function method(string $method): string
    {
        return in_array($method, self::METHODS, true) ? $method : 'manual_review';
    }

    private function rejectionCode(string $code): string
    {
        return in_array($code, self::REJECTION_CODES, true) ? $code : 'other';
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return substr($value, 0, $max);
    }

    /** @param array<string, mixed> $metadata */
    private function encodeMetadata(array $metadata): ?string
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !in_array($key, self::METADATA_KEYS, true)) {
                continue;
            }

            if (is_scalar($value)) {
                $clean[$key] = substr((string) $value, 0, 80);
            }
        }

        if ($clean === []) {
            return null;
        }

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row, bool $includeInternal = false): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['reviewed_by_user_id'] = isset($row['reviewed_by_user_id']) && $row['reviewed_by_user_id'] !== null
            ? (int) $row['reviewed_by_user_id']
            : null;

        if (!$includeInternal) {
            unset($row['provider_reference'], $row['metadata_json']);
        } elseif (isset($row['metadata_json']) && is_string($row['metadata_json'])) {
            $decoded = json_decode($row['metadata_json'], true);
            $row['metadata'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }
}
