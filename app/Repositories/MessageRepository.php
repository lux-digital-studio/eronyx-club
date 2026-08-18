<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class MessageRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function insert(int $conversationId, int $senderUserId, string $body): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO messages (conversation_id, sender_user_id, body)
             VALUES (:conversation_id, :sender_user_id, :body)'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'sender_user_id' => $senderUserId,
            'body' => $body,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function findByConversation(int $conversationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, conversation_id, sender_user_id, body, created_at
             FROM messages
             WHERE conversation_id = :conversation_id
             ORDER BY id ASC'
        );
        $statement->execute(['conversation_id' => $conversationId]);

        return array_map(
            static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['conversation_id'] = (int) $row['conversation_id'];
                $row['sender_user_id'] = (int) $row['sender_user_id'];

                return $row;
            },
            $statement->fetchAll()
        );
    }

    public function maxId(int $conversationId): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT MAX(id) FROM messages WHERE conversation_id = :conversation_id'
        );
        $statement->execute(['conversation_id' => $conversationId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }
}
