<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AuditLogRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /** @param array<string, mixed> $metadata */
    public function record(
        ?int $actorUserId,
        string $eventType,
        string $entityType,
        ?int $entityId,
        array $metadata = []
    ): int {
        $json = $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $json = null;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, event_type, entity_type, entity_id, metadata_json)
             VALUES (:actor_user_id, :event_type, :entity_type, :entity_id, :metadata_json)'
        );
        $statement->execute([
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata_json' => $json,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function findRecent(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->prepare(
            'SELECT id, actor_user_id, event_type, entity_type, entity_id, metadata_json, created_at
             FROM audit_logs
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $row): array => $this->normalize($row), $statement->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function findByEntity(string $entityType, int $entityId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, actor_user_id, event_type, entity_type, entity_id, metadata_json, created_at
             FROM audit_logs
             WHERE entity_type = :entity_type
                AND entity_id = :entity_id
             ORDER BY id DESC'
        );
        $statement->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        return array_map(fn (array $row): array => $this->normalize($row), $statement->fetchAll());
    }

    /** @param array<string, mixed> $row */
    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['actor_user_id'] = $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null;
        $row['entity_id'] = $row['entity_id'] !== null ? (int) $row['entity_id'] : null;
        $metadata = [];

        if (is_string($row['metadata_json']) && $row['metadata_json'] !== '') {
            $decoded = json_decode($row['metadata_json'], true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        $row['metadata'] = $metadata;

        return $row;
    }
}
