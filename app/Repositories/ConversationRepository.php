<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

final class ConversationRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, listing_id, created_by_user_id, status, last_message_at, created_at, updated_at
             FROM conversations
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalizeConversation($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByListingAndCreatedBy(int $listingId, int $createdByUserId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, listing_id, created_by_user_id, status, last_message_at, created_at, updated_at
             FROM conversations
             WHERE listing_id = :listing_id
                AND created_by_user_id = :created_by_user_id
             LIMIT 1'
        );
        $statement->execute([
            'listing_id' => $listingId,
            'created_by_user_id' => $createdByUserId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalizeConversation($row) : null;
    }

    public function create(int $listingId, int $createdByUserId): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO conversations (listing_id, created_by_user_id, status)
             VALUES (:listing_id, :created_by_user_id, 'active')"
        );
        $statement->execute([
            'listing_id' => $listingId,
            'created_by_user_id' => $createdByUserId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function reopen(int $conversationId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE conversations
             SET status = 'active'
             WHERE id = :id
                AND status = 'closed'"
        );
        $statement->execute(['id' => $conversationId]);
    }

    public function addParticipant(int $conversationId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_participants (conversation_id, user_id)
             VALUES (:conversation_id, :user_id)'
        );

        try {
            $statement->execute([
                'conversation_id' => $conversationId,
                'user_id' => $userId,
            ]);
        } catch (PDOException $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }
        }
    }

    public function isParticipant(int $conversationId, int $userId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM conversation_participants
             WHERE conversation_id = :conversation_id
                AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function otherParticipantUserId(int $conversationId, int $userId): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id
             FROM conversation_participants
             WHERE conversation_id = :conversation_id
                AND user_id <> :user_id
             LIMIT 1'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
        ]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    public function touchLastMessageAt(int $conversationId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE conversations
             SET last_message_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute(['id' => $conversationId]);
    }

    public function updateLastReadMessageId(int $conversationId, int $userId, int $messageId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE conversation_participants
             SET last_read_message_id = :last_read_message_id
             WHERE conversation_id = :conversation_id
                AND user_id = :user_id
                AND (
                    last_read_message_id IS NULL
                    OR last_read_message_id < :last_read_message_id_cmp
                )'
        );
        $statement->execute([
            'last_read_message_id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'last_read_message_id_cmp' => $messageId,
        ]);
    }

    public function unreadConversationCount(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM conversation_participants me
             WHERE me.user_id = :user_id
                AND EXISTS (
                    SELECT 1
                    FROM messages um
                    WHERE um.conversation_id = me.conversation_id
                        AND um.sender_user_id <> :sender_user_id
                        AND (
                            me.last_read_message_id IS NULL
                            OR um.id > me.last_read_message_id
                        )
                )'
        );
        $statement->execute([
            'user_id' => $userId,
            'sender_user_id' => $userId,
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Inbox rows for one user. Last message, other participant and unread are
     * loaded in this query (no per-row follow-up queries).
     *
     * @return list<array<string, mixed>>
     */
    public function findInboxForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id,
                    c.listing_id,
                    c.status,
                    c.last_message_at,
                    c.created_at,
                    other.user_id AS other_user_id,
                    p.display_name AS other_display_name,
                    last_msg.body AS last_message_body,
                    last_msg.created_at AS last_message_created_at,
                    l.title AS listing_title,
                    l.slug AS listing_slug,
                    l.status AS listing_status,
                    l.visibility AS listing_visibility,
                    l.published_at AS listing_published_at,
                    l.deleted_at AS listing_deleted_at,
                    (
                        SELECT COUNT(*)
                        FROM messages um
                        WHERE um.conversation_id = c.id
                            AND um.sender_user_id <> :unread_user_id
                            AND (
                                me.last_read_message_id IS NULL
                                OR um.id > me.last_read_message_id
                            )
                    ) AS unread_count
             FROM conversation_participants me
             INNER JOIN conversations c ON c.id = me.conversation_id
             INNER JOIN conversation_participants other
                ON other.conversation_id = c.id
                AND other.user_id <> :other_user_id
             LEFT JOIN profiles p
                ON p.user_id = other.user_id
                AND p.deleted_at IS NULL
             LEFT JOIN messages last_msg ON last_msg.id = (
                SELECT m2.id
                FROM messages m2
                WHERE m2.conversation_id = c.id
                ORDER BY m2.id DESC
                LIMIT 1
             )
             LEFT JOIN listings l ON l.id = c.listing_id
             WHERE me.user_id = :me_user_id
             ORDER BY COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC'
        );
        $statement->execute([
            'unread_user_id' => $userId,
            'other_user_id' => $userId,
            'me_user_id' => $userId,
        ]);

        return array_map(
            fn (array $row): array => $this->normalizeInboxRow($row),
            $statement->fetchAll()
        );
    }

    /** @return array<string, mixed>|null */
    public function findThreadMeta(int $conversationId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id,
                    c.listing_id,
                    c.status,
                    c.last_message_at,
                    c.created_at,
                    other.user_id AS other_user_id,
                    p.display_name AS other_display_name,
                    other_user.status AS other_user_status,
                    other_user.deleted_at AS other_user_deleted_at,
                    l.title AS listing_title,
                    l.slug AS listing_slug,
                    l.status AS listing_status,
                    l.visibility AS listing_visibility,
                    l.published_at AS listing_published_at,
                    l.deleted_at AS listing_deleted_at
             FROM conversation_participants me
             INNER JOIN conversations c ON c.id = me.conversation_id
             INNER JOIN conversation_participants other
                ON other.conversation_id = c.id
                AND other.user_id <> :other_user_id
             INNER JOIN users other_user ON other_user.id = other.user_id
             LEFT JOIN profiles p
                ON p.user_id = other.user_id
                AND p.deleted_at IS NULL
             LEFT JOIN listings l ON l.id = c.listing_id
             WHERE c.id = :conversation_id
                AND me.user_id = :me_user_id
             LIMIT 1'
        );
        $statement->execute([
            'other_user_id' => $userId,
            'conversation_id' => $conversationId,
            'me_user_id' => $userId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalizeThreadMeta($row) : null;
    }

    public function isDuplicateKey(PDOException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    /** @param array<string, mixed> $row */
    private function normalizeConversation(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['listing_id'] = $row['listing_id'] !== null ? (int) $row['listing_id'] : null;
        $row['created_by_user_id'] = (int) $row['created_by_user_id'];

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function normalizeInboxRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['listing_id'] = $row['listing_id'] !== null ? (int) $row['listing_id'] : null;
        $row['other_user_id'] = (int) $row['other_user_id'];
        $row['unread_count'] = (int) $row['unread_count'];
        $row['listing_visible'] = $this->listingIsPubliclyReferenceable($row);
        $row['other_display_name'] = $this->safeDisplayName($row['other_display_name'] ?? null);

        if (!$row['listing_visible']) {
            $row['listing_title'] = null;
            $row['listing_slug'] = null;
        }

        unset(
            $row['listing_status'],
            $row['listing_visibility'],
            $row['listing_published_at'],
            $row['listing_deleted_at']
        );

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function normalizeThreadMeta(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['listing_id'] = $row['listing_id'] !== null ? (int) $row['listing_id'] : null;
        $row['other_user_id'] = (int) $row['other_user_id'];
        $row['listing_visible'] = $this->listingIsPubliclyReferenceable($row);
        $row['other_display_name'] = $this->safeDisplayName($row['other_display_name'] ?? null);
        $row['other_is_active'] = ($row['other_user_status'] ?? '') === 'active'
            && ($row['other_user_deleted_at'] ?? null) === null;

        if (!$row['listing_visible']) {
            $row['listing_title'] = null;
            $row['listing_slug'] = null;
        }

        unset(
            $row['other_user_status'],
            $row['other_user_deleted_at'],
            $row['listing_status'],
            $row['listing_visibility'],
            $row['listing_published_at'],
            $row['listing_deleted_at']
        );

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function listingIsPubliclyReferenceable(array $row): bool
    {
        return ($row['listing_id'] ?? null) !== null
            && ($row['listing_deleted_at'] ?? null) === null
            && ($row['listing_status'] ?? '') === 'published'
            && ($row['listing_published_at'] ?? null) !== null
            && ($row['listing_published_at'] ?? '') !== ''
            && in_array($row['listing_visibility'] ?? '', ['public', 'unlisted'], true);
    }

    private function safeDisplayName(mixed $name): string
    {
        $name = is_string($name) ? trim($name) : '';

        return $name !== '' ? $name : 'Usuario';
    }
}
