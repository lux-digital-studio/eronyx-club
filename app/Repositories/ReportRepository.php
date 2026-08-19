<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

final class ReportRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function create(
        int $reporterUserId,
        string $targetType,
        int $targetId,
        string $reasonCode,
        ?string $details
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO reports (reporter_user_id, target_type, target_id, reason_code, details, status)
             VALUES (:reporter_user_id, :target_type, :target_id, :reason_code, :details, :status)'
        );
        $statement->execute([
            'reporter_user_id' => $reporterUserId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason_code' => $reasonCode,
            'details' => $details,
            'status' => 'open',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, reporter_user_id, target_type, target_id, reason_code, details, status,
                    resolved_at, resolved_by_user_id, created_at, updated_at
             FROM reports
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findOpenDuplicate(int $reporterUserId, string $targetType, int $targetId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, reporter_user_id, target_type, target_id, reason_code, details, status,
                    resolved_at, resolved_by_user_id, created_at, updated_at
             FROM reports
             WHERE reporter_user_id = :reporter_user_id
                AND target_type = :target_type
                AND target_id = :target_id
                AND status IN ('open', 'in_review')
             LIMIT 1"
        );
        $statement->execute([
            'reporter_user_id' => $reporterUserId,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function findOpenReports(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, reporter_user_id, target_type, target_id, reason_code, details, status,
                    resolved_at, resolved_by_user_id, created_at, updated_at
             FROM reports
             WHERE status IN ('open', 'in_review')
             ORDER BY CASE status WHEN 'open' THEN 0 WHEN 'in_review' THEN 1 ELSE 2 END,
                      created_at ASC,
                      id ASC"
        );

        return array_map(fn (array $row): array => $this->normalize($row), $statement->fetchAll());
    }

    public function countOpenReports(): int
    {
        $value = $this->pdo->query(
            "SELECT COUNT(*) FROM reports WHERE status IN ('open', 'in_review')"
        )->fetchColumn();

        return (int) $value;
    }

    public function markInReview(int $id): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE reports
             SET status = 'in_review'
             WHERE id = :id
                AND status = 'open'"
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function resolve(int $id, int $moderatorUserId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE reports
             SET status = 'resolved',
                 resolved_at = CURRENT_TIMESTAMP,
                 resolved_by_user_id = :resolved_by_user_id
             WHERE id = :id
                AND status IN ('open', 'in_review')"
        );
        $statement->execute([
            'id' => $id,
            'resolved_by_user_id' => $moderatorUserId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function dismiss(int $id, int $moderatorUserId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE reports
             SET status = 'dismissed',
                 resolved_at = CURRENT_TIMESTAMP,
                 resolved_by_user_id = :resolved_by_user_id
             WHERE id = :id
                AND status IN ('open', 'in_review')"
        );
        $statement->execute([
            'id' => $id,
            'resolved_by_user_id' => $moderatorUserId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function isDuplicateKey(PDOException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    /** @param array<string, mixed> $row */
    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['reporter_user_id'] = (int) $row['reporter_user_id'];
        $row['target_id'] = (int) $row['target_id'];
        $row['resolved_by_user_id'] = $row['resolved_by_user_id'] !== null
            ? (int) $row['resolved_by_user_id']
            : null;

        return $row;
    }
}
