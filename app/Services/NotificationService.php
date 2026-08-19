<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;

final class NotificationService
{
    public const TYPES = [
        'new_message',
        'listing_favorited',
        'creator_application_approved',
        'creator_application_rejected',
        'listing_approved',
        'listing_rejected',
        'listing_suspended',
        'listing_restored',
        'creator_suspended',
        'creator_restored',
        'order_completed',
        'order_paid',
        'report_updated',
    ];

    private const ENTITY_TYPES = [
        'message',
        'conversation',
        'listing',
        'order',
        'report',
        'creator_application',
        'user',
    ];

    public const TITLE_MAX = 180;
    public const BODY_MAX = 500;
    public const PER_PAGE = 20;

    private NotificationRepository $notifications;
    private UserRepository $users;

    public function __construct(
        ?NotificationRepository $notifications = null,
        ?UserRepository $users = null,
        ?\PDO $pdo = null
    ) {
        $pdo ??= $notifications?->connection() ?? (new Database())->connection();
        $this->notifications = $notifications ?? new NotificationRepository($pdo);
        $this->users = $users ?? new UserRepository($pdo);
    }

    public function notify(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?int $actorUserId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $actionUrl = null,
        ?string $dedupeKey = null
    ): ?int {
        try {
            return $this->attemptNotify(
                $userId,
                $type,
                $title,
                $body,
                $actorUserId,
                $entityType,
                $entityId,
                $actionUrl,
                $dedupeKey
            );
        } catch (\PDOException) {
            return null;
        }
    }

    /**
     * @return array{
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   perPage: int,
     *   currentPage: int,
     *   lastPage: int
     * }
     */
    public function listForUser(int $userId, mixed $page): array
    {
        $page = $this->parsePage($page);
        $total = $this->notifications->countForUser($userId);
        $lastPage = $total === 0 ? 1 : (int) ceil($total / self::PER_PAGE);

        if ($total === 0) {
            $page = 1;
        } elseif ($page > $lastPage) {
            $page = $lastPage;
        }

        $offset = ($page - 1) * self::PER_PAGE;
        $items = $total === 0 ? [] : $this->notifications->findForUser($userId, self::PER_PAGE, $offset);

        return [
            'items' => $items,
            'total' => $total,
            'perPage' => self::PER_PAGE,
            'currentPage' => $page,
            'lastPage' => $lastPage,
        ];
    }

    public function countUnread(int $userId): int
    {
        return $this->notifications->countUnreadForUser($userId);
    }

    public function markRead(int $id, int $userId): bool
    {
        return $this->notifications->markRead($id, $userId);
    }

    public function markAllRead(int $userId): int
    {
        return $this->notifications->markAllRead($userId);
    }

    public static function safeActionUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (
            !str_starts_with($url, '/')
            || str_starts_with($url, '//')
            || strpbrk($url, "\r\n\0:\\ ?#") !== false
            || preg_match('/\A\/[A-Za-z0-9\/_\-]*\z/', $url) !== 1
        ) {
            return null;
        }

        return $url;
    }

    public function normalizeActionUrl(?string $url): ?string
    {
        return self::safeActionUrl($url);
    }

    private function attemptNotify(
        int $userId,
        string $type,
        string $title,
        ?string $body,
        ?int $actorUserId,
        ?string $entityType,
        ?int $entityId,
        ?string $actionUrl,
        ?string $dedupeKey
    ): ?int {
        if (!in_array($type, self::TYPES, true)) {
            return null;
        }

        if ($userId <= 0 || $actorUserId === $userId) {
            return null;
        }

        $recipient = $this->users->findExistingById($userId);

        if ($recipient === null || $recipient['deleted_at'] !== null) {
            return null;
        }

        if ($actorUserId !== null) {
            $actor = $this->users->findExistingById($actorUserId);

            if ($actor === null || $actor['deleted_at'] !== null) {
                $actorUserId = null;
            }
        }

        $title = $this->plainText($title, self::TITLE_MAX);

        if ($title === '') {
            return null;
        }

        $normalizedUrl = $this->normalizeActionUrl($actionUrl);

        if ($actionUrl !== null && $actionUrl !== '' && $normalizedUrl === null) {
            return null;
        }

        $normalizedEntityType = $this->normalizeEntityType($entityType);
        $normalizedEntityId = ($normalizedEntityType !== null && $entityId !== null && $entityId > 0)
            ? $entityId
            : null;
        $normalizedDedupe = $this->normalizeDedupeKey($dedupeKey);

        if ($normalizedDedupe !== null) {
            $existingId = $this->notifications->findIdByDedupeKey($normalizedDedupe);

            if ($existingId !== null) {
                return $existingId;
            }
        }

        return $this->notifications->create([
            'user_id' => $userId,
            'actor_user_id' => $actorUserId,
            'type' => $type,
            'title' => $title,
            'body' => $this->plainText($body ?? '', self::BODY_MAX) ?: null,
            'entity_type' => $normalizedEntityType,
            'entity_id' => $normalizedEntityId,
            'action_url' => $normalizedUrl,
            'dedupe_key' => $normalizedDedupe,
        ]);
    }

    private function normalizeEntityType(?string $entityType): ?string
    {
        if ($entityType === null || $entityType === '') {
            return null;
        }

        return in_array($entityType, self::ENTITY_TYPES, true) ? $entityType : null;
    }

    private function normalizeDedupeKey(?string $dedupeKey): ?string
    {
        if ($dedupeKey === null) {
            return null;
        }

        $dedupeKey = strtolower(trim($dedupeKey));

        if ($dedupeKey === '' || strlen($dedupeKey) > 180 || preg_match('/\A[a-z0-9_.:\-]+\z/', $dedupeKey) !== 1) {
            return null;
        }

        return $dedupeKey;
    }

    private function plainText(string $value, int $max): string
    {
        $value = trim(str_replace(["\0", "\r"], '', $value));

        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, 0, $max, 'UTF-8');
        }

        return substr($value, 0, $max);
    }

    private function parsePage(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,8}\z/', $value) === 1) {
            return (int) $value;
        }

        return 1;
    }
}
