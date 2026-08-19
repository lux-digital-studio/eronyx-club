<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

final class NotificationRepository
{
    public const PER_PAGE = 20;

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array{
     *   user_id: int,
     *   actor_user_id: int|null,
     *   type: string,
     *   title: string,
     *   body: string|null,
     *   entity_type: string|null,
     *   entity_id: int|null,
     *   action_url: string|null,
     *   dedupe_key: string|null
     * } $data
     */
    public function create(array $data): ?int
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO notifications (
                    user_id, actor_user_id, type, title, body, entity_type, entity_id, action_url, dedupe_key
                 ) VALUES (
                    :user_id, :actor_user_id, :type, :title, :body, :entity_type, :entity_id, :action_url, :dedupe_key
                 )'
            );
            $statement->execute([
                'user_id' => $data['user_id'],
                'actor_user_id' => $data['actor_user_id'],
                'type' => $data['type'],
                'title' => $data['title'],
                'body' => $data['body'],
                'entity_type' => $data['entity_type'],
                'entity_id' => $data['entity_id'],
                'action_url' => $data['action_url'],
                'dedupe_key' => $data['dedupe_key'],
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicateKey($exception) && is_string($data['dedupe_key'])) {
                return $this->findIdByDedupeKey($data['dedupe_key']);
            }

            if ($this->isForeignKeyFailure($exception)) {
                return null;
            }

            throw $exception;
        }

        $id = (int) $this->pdo->lastInsertId();

        return $id > 0 ? $id : null;
    }

    public function existsByDedupeKey(string $dedupeKey): bool
    {
        return $this->findIdByDedupeKey($dedupeKey) !== null;
    }

    public function findIdByDedupeKey(string $dedupeKey): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM notifications WHERE dedupe_key = :dedupe_key LIMIT 1'
        );
        $statement->execute(['dedupe_key' => $dedupeKey]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findForUser(int $userId, int $limit, int $offset): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, actor_user_id, type, title, body, entity_type, entity_id, action_url, dedupe_key, read_at, created_at, updated_at
             FROM notifications
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $row): array => $this->normalize($row), $statement->fetchAll());
    }

    public function countForUser(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findByIdForUser(int $id, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, actor_user_id, type, title, body, entity_type, entity_id, action_url, dedupe_key, read_at, created_at, updated_at
             FROM notifications
             WHERE id = :id
                AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    public function countUnreadForUser(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM notifications
             WHERE user_id = :user_id
                AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function markRead(int $id, int $userId): bool
    {
        if ($this->findByIdForUser($id, $userId) === null) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE notifications
             SET read_at = CURRENT_TIMESTAMP
             WHERE id = :id
                AND user_id = :user_id
                AND read_at IS NULL'
        );
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);

        return true;
    }

    public function markAllRead(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE notifications
             SET read_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id
                AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->rowCount();
    }

    private function isDuplicateKey(PDOException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            || $exception->getCode() === '23000'
            || str_contains($exception->getMessage(), '1062');
    }

    private function isForeignKeyFailure(PDOException $exception): bool
    {
        $code = (int) ($exception->errorInfo[1] ?? 0);

        return $code === 1451 || $code === 1452;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['actor_user_id'] = $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null;
        $row['entity_id'] = $row['entity_id'] !== null ? (int) $row['entity_id'] : null;

        return $row;
    }
}
