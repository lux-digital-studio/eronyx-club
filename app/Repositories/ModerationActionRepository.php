<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ModerationActionRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function createAction(
        int $moderatorUserId,
        string $targetType,
        int $targetId,
        string $actionType,
        ?string $reasonCode = null,
        ?string $notes = null,
        ?string $previousStatus = null
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO moderation_actions (
                moderator_user_id, target_type, target_id, action_type, reason_code, notes, previous_status
             ) VALUES (
                :moderator_user_id, :target_type, :target_id, :action_type, :reason_code, :notes, :previous_status
             )'
        );
        $statement->execute([
            'moderator_user_id' => $moderatorUserId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action_type' => $actionType,
            'reason_code' => $reasonCode,
            'notes' => $notes,
            'previous_status' => $previousStatus,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function findByTarget(string $targetType, int $targetId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, moderator_user_id, target_type, target_id, action_type, reason_code, notes,
                    previous_status, created_at
             FROM moderation_actions
             WHERE target_type = :target_type
                AND target_id = :target_id
             ORDER BY id DESC'
        );
        $statement->execute([
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);

        return array_map(fn (array $row): array => $this->normalize($row), $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findLatestByTargetAndType(string $targetType, int $targetId, string $actionType): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, moderator_user_id, target_type, target_id, action_type, reason_code, notes,
                    previous_status, created_at
             FROM moderation_actions
             WHERE target_type = :target_type
                AND target_id = :target_id
                AND action_type = :action_type
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action_type' => $actionType,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function findRecent(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->prepare(
            'SELECT id, moderator_user_id, target_type, target_id, action_type, reason_code, notes,
                    previous_status, created_at
             FROM moderation_actions
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $row): array => $this->normalize($row), $statement->fetchAll());
    }

    public function countByTargetAndType(string $targetType, int $targetId, string $actionType): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM moderation_actions
             WHERE target_type = :target_type
                AND target_id = :target_id
                AND action_type = :action_type'
        );
        $statement->execute([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action_type' => $actionType,
        ]);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['moderator_user_id'] = (int) $row['moderator_user_id'];
        $row['target_id'] = (int) $row['target_id'];

        return $row;
    }
}
