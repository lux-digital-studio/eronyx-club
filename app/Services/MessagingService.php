<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConversationRepository;
use App\Repositories\ListingRepository;
use App\Repositories\MessageRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use PDOException;
use RuntimeException;
use Throwable;

final class MessagingService
{
    private NotificationService $notifications;

    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly MessageRepository $messages,
        private readonly ListingRepository $listings,
        ?NotificationService $notifications = null
    ) {
        $pdo = $this->conversations->connection();
        $this->notifications = $notifications ?? new NotificationService(
            new NotificationRepository($pdo),
            new UserRepository($pdo)
        );
    }

    /**
     * Start or reuse the buyer+listing conversation. Seller is always the listing owner.
     */
    public function startConversation(int $buyerUserId, int $listingId): int
    {
        $listing = $this->listings->findById($listingId);

        if ($listing === null || !$this->isContactableListing($listing)) {
            throw new RuntimeException('not_found');
        }

        $sellerUserId = (int) $listing['owner_user_id'];

        if ($sellerUserId === $buyerUserId) {
            throw new RuntimeException('forbidden');
        }

        $existing = $this->conversations->findByListingAndCreatedBy($listingId, $buyerUserId);

        if ($existing !== null) {
            $this->ensurePair($existing['id'], $buyerUserId, $sellerUserId);
            $this->conversations->reopen($existing['id']);

            return $existing['id'];
        }

        $pdo = $this->conversations->connection();

        try {
            $pdo->beginTransaction();
            $conversationId = $this->conversations->create($listingId, $buyerUserId);
            $this->ensurePair($conversationId, $buyerUserId, $sellerUserId);
            $pdo->commit();

            return $conversationId;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (!$this->conversations->isDuplicateKey($exception)) {
                throw $exception;
            }

            $recovered = $this->conversations->findByListingAndCreatedBy($listingId, $buyerUserId);

            if ($recovered === null) {
                throw $exception;
            }

            $this->ensurePair($recovered['id'], $buyerUserId, $sellerUserId);
            $this->conversations->reopen($recovered['id']);

            return $recovered['id'];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function inbox(int $userId): array
    {
        return $this->conversations->findInboxForUser($userId);
    }

    public function unreadConversationCount(int $userId): int
    {
        return $this->conversations->unreadConversationCount($userId);
    }

    /**
     * @return array{conversation: array<string, mixed>, messages: list<array<string, mixed>>, can_send: bool}
     */
    public function openConversation(int $userId, int $conversationId): array
    {
        $conversation = $this->authorizedConversation($userId, $conversationId);
        $this->markConversationRead($userId, $conversationId);

        return [
            'conversation' => $conversation,
            'messages' => $this->messages->findByConversation($conversationId),
            'can_send' => $this->canSend($conversation),
        ];
    }

    public function sendMessage(int $senderUserId, int $conversationId, string $body): void
    {
        $conversation = $this->authorizedConversation($senderUserId, $conversationId);

        if (!$this->canSend($conversation)) {
            if (($conversation['status'] ?? '') !== 'active') {
                throw new RuntimeException('closed');
            }

            throw new RuntimeException('recipient_inactive');
        }

        $pdo = $this->conversations->connection();

        try {
            $pdo->beginTransaction();
            $messageId = $this->messages->insert($conversationId, $senderUserId, $body);
            $this->conversations->touchLastMessageAt($conversationId);
            $recipientUserId = $this->conversations->otherParticipantUserId($conversationId, $senderUserId);

            if ($recipientUserId !== null && $recipientUserId !== $senderUserId) {
                $this->notifications->notify(
                    $recipientUserId,
                    'new_message',
                    'Tienes un mensaje nuevo',
                    'Has recibido un mensaje en una de tus conversaciones.',
                    $senderUserId,
                    'message',
                    $messageId,
                    '/account/messages/' . $conversationId,
                    'message:' . $messageId . ':recipient:' . $recipientUserId
                );
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function markConversationRead(int $userId, int $conversationId): void
    {
        $maxId = $this->messages->maxId($conversationId);

        if ($maxId === null) {
            return;
        }

        $this->conversations->updateLastReadMessageId($conversationId, $userId, $maxId);
    }

    /** @return array<string, mixed> */
    private function authorizedConversation(int $userId, int $conversationId): array
    {
        if ($this->conversations->findById($conversationId) === null) {
            throw new RuntimeException('not_found');
        }

        $thread = $this->conversations->findThreadMeta($conversationId, $userId);

        if ($thread === null) {
            throw new RuntimeException('forbidden');
        }

        return $thread;
    }

    /** @param array<string, mixed> $conversation */
    private function canSend(array $conversation): bool
    {
        return ($conversation['status'] ?? '') === 'active'
            && ($conversation['other_is_active'] ?? false) === true;
    }

    private function ensurePair(int $conversationId, int $buyerUserId, int $sellerUserId): void
    {
        $this->conversations->addParticipant($conversationId, $buyerUserId);
        $this->conversations->addParticipant($conversationId, $sellerUserId);
    }

    /** @param array<string, mixed> $listing */
    private function isContactableListing(array $listing): bool
    {
        return ($listing['status'] ?? '') === 'published'
            && ($listing['published_at'] ?? null) !== null
            && ($listing['published_at'] ?? '') !== ''
            && in_array($listing['visibility'] ?? '', ['public', 'unlisted'], true);
    }
}
