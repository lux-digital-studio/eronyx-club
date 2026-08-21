<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AdminRepository
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

    /** @return array<string, array<string, int>> */
    public function dashboardCounts(): array
    {
        return [
            'users' => $this->groupCounts('SELECT status AS k, COUNT(*) AS c FROM users WHERE deleted_at IS NULL GROUP BY status'),
            'creators' => $this->groupCounts('SELECT status AS k, COUNT(*) AS c FROM creator_profiles WHERE deleted_at IS NULL GROUP BY status'),
            'listings' => $this->groupCounts('SELECT status AS k, COUNT(*) AS c FROM listings WHERE deleted_at IS NULL GROUP BY status'),
            'orders' => $this->groupCounts('SELECT status AS k, COUNT(*) AS c FROM orders WHERE deleted_at IS NULL GROUP BY status'),
            'reports' => $this->groupCounts('SELECT status AS k, COUNT(*) AS c FROM reports GROUP BY status'),
            'audit' => ['total' => (int) $this->pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recentAudit(int $limit = 8): array
    {
        $limit = max(1, min(20, $limit));
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.actor_user_id, a.event_type, a.entity_type, a.entity_id, a.created_at,
                    p.username AS actor_username
             FROM audit_logs a
             LEFT JOIN profiles p ON p.user_id = a.actor_user_id AND p.deleted_at IS NULL
             ORDER BY a.id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $row): array => $this->normalizeAuditListRow($row), $statement->fetchAll());
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listUsers(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->userFilters($filters);
        $order = $this->userOrder((string) ($filters['sort'] ?? 'newest'));
        $sql = 'SELECT u.id, u.email, u.status, u.email_verified_at, u.created_at, u.last_login_at,
                       p.username, p.display_name,
                       GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR \',\') AS roles
                FROM users u
                LEFT JOIN profiles p ON p.user_id = u.id AND p.deleted_at IS NULL
                LEFT JOIN user_roles ur ON ur.user_id = u.id
                LEFT JOIN roles r ON r.id = ur.role_id
                WHERE ' . $where . '
                GROUP BY u.id, u.email, u.status, u.email_verified_at, u.created_at, u.last_login_at,
                         p.username, p.display_name
                ' . $order . '
                LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $params);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $row): array => $this->normalizeUserListRow($row), $statement->fetchAll());
    }

    /** @param array<string, mixed> $filters */
    public function countUsers(array $filters): int
    {
        [$where, $params] = $this->userFilters($filters);
        $sql = 'SELECT COUNT(DISTINCT u.id)
                FROM users u
                LEFT JOIN profiles p ON p.user_id = u.id AND p.deleted_at IS NULL
                LEFT JOIN user_roles ur ON ur.user_id = u.id
                LEFT JOIN roles r ON r.id = ur.role_id
                WHERE ' . $where;
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $params);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findUserDetail(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.email, u.status, u.email_verified_at, u.created_at, u.updated_at, u.last_login_at, u.deleted_at,
                    p.display_name, p.username, p.bio,
                    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR \',\') AS roles
             FROM users u
             LEFT JOIN profiles p ON p.user_id = u.id AND p.deleted_at IS NULL
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.id = :id
             GROUP BY u.id, u.email, u.status, u.email_verified_at, u.created_at, u.updated_at, u.last_login_at, u.deleted_at,
                      p.display_name, p.username, p.bio
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalizeUserDetail($row) : null;
    }

    /** @return array<string, int> */
    public function userActivityCounts(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM listings WHERE owner_user_id = :uid_listings AND deleted_at IS NULL) AS listings,
                (SELECT COUNT(*) FROM orders WHERE buyer_user_id = :uid_orders AND deleted_at IS NULL) AS orders,
                (SELECT COUNT(*) FROM favorites WHERE user_id = :uid_favorites) AS favorites,
                (SELECT COUNT(*) FROM conversation_participants WHERE user_id = :uid_conversations) AS conversations,
                (SELECT COUNT(*) FROM reports WHERE reporter_user_id = :uid_reports) AS reports,
                (SELECT COUNT(*) FROM notifications WHERE user_id = :uid_notifications) AS notifications'
        );
        $statement->execute([
            'uid_listings' => $userId,
            'uid_orders' => $userId,
            'uid_favorites' => $userId,
            'uid_conversations' => $userId,
            'uid_reports' => $userId,
            'uid_notifications' => $userId,
        ]);
        $row = $statement->fetch();

        return [
            'listings' => (int) ($row['listings'] ?? 0),
            'orders' => (int) ($row['orders'] ?? 0),
            'favorites' => (int) ($row['favorites'] ?? 0),
            'conversations' => (int) ($row['conversations'] ?? 0),
            'reports' => (int) ($row['reports'] ?? 0),
            'notifications' => (int) ($row['notifications'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listCreators(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->creatorFilters($filters);
        $order = $this->creatorOrder((string) ($filters['sort'] ?? 'newest'));
        $sql = 'SELECT cp.id, cp.user_id, cp.status, cp.created_at,
                       u.email, u.status AS user_status, u.email_verified_at,
                       p.username, p.display_name,
                       EXISTS (
                           SELECT 1 FROM user_roles ur
                           INNER JOIN roles r ON r.id = ur.role_id
                           WHERE ur.user_id = cp.user_id AND r.name = \'creator\'
                       ) AS has_creator_role,
                       (SELECT COUNT(*) FROM listings l WHERE l.owner_user_id = cp.user_id AND l.deleted_at IS NULL) AS listing_count
                FROM creator_profiles cp
                INNER JOIN users u ON u.id = cp.user_id
                LEFT JOIN profiles p ON p.user_id = cp.user_id AND p.deleted_at IS NULL
                WHERE ' . $where . '
                ' . $order . '
                LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $params);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $row): array => $this->normalizeCreatorListRow($row), $statement->fetchAll());
    }

    /** @param array<string, mixed> $filters */
    public function countCreators(array $filters): int
    {
        [$where, $params] = $this->creatorFilters($filters);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM creator_profiles cp
             INNER JOIN users u ON u.id = cp.user_id
             LEFT JOIN profiles p ON p.user_id = cp.user_id AND p.deleted_at IS NULL
             WHERE ' . $where
        );
        $this->bind($statement, $params);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findCreatorDetail(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT cp.id, cp.user_id, cp.status, cp.created_at, cp.updated_at, cp.deleted_at,
                    u.email, u.status AS user_status, u.email_verified_at, u.created_at AS user_created_at,
                    p.display_name, p.username, p.bio
             FROM creator_profiles cp
             INNER JOIN users u ON u.id = cp.user_id
             LEFT JOIN profiles p ON p.user_id = cp.user_id AND p.deleted_at IS NULL
             WHERE cp.user_id = :user_id
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['email_verified'] = is_string($row['email_verified_at'] ?? null);
        $row['listing_stats'] = $this->listingStatsForOwner($userId);
        $row['age_verification'] = $this->latestAgeVerification($userId);
        $row['roles'] = $this->rolesForUser($userId);

        return $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listListings(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->listingFilters($filters);
        $order = $this->listingOrder((string) ($filters['sort'] ?? 'newest'));
        $sql = 'SELECT l.id, l.owner_user_id, l.title, l.status, l.visibility, l.listing_type,
                       l.price, l.currency, l.published_at, l.created_at,
                       p.username AS owner_username, p.display_name AS owner_display_name
                FROM listings l
                LEFT JOIN profiles p ON p.user_id = l.owner_user_id AND p.deleted_at IS NULL
                WHERE ' . $where . '
                ' . $order . '
                LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $params);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['owner_user_id'] = (int) $row['owner_user_id'];
        }

        return $rows;
    }

    /** @param array<string, mixed> $filters */
    public function countListings(array $filters): int
    {
        [$where, $params] = $this->listingFilters($filters);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM listings l
             LEFT JOIN profiles p ON p.user_id = l.owner_user_id AND p.deleted_at IS NULL
             WHERE ' . $where
        );
        $this->bind($statement, $params);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findListingDetail(int $listingId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.id, l.owner_user_id, l.creator_profile_id, l.title, l.slug, l.description,
                    l.listing_type, l.status, l.price, l.currency, l.visibility,
                    l.published_at, l.created_at, l.updated_at,
                    p.username AS owner_username, p.display_name AS owner_display_name
             FROM listings l
             LEFT JOIN profiles p ON p.user_id = l.owner_user_id AND p.deleted_at IS NULL
             WHERE l.id = :id AND l.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $listingId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['owner_user_id'] = (int) $row['owner_user_id'];
        $row['creator_profile_id'] = $row['creator_profile_id'] !== null ? (int) $row['creator_profile_id'] : null;
        $row['counts'] = $this->listingActivityCounts($listingId);
        $row['categories'] = $this->listingCategories($listingId);
        $row['media'] = $this->listingMediaSummary($listingId);

        return $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listOrders(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->orderFilters($filters);
        $order = $this->orderOrder((string) ($filters['sort'] ?? 'newest'));
        $sql = 'SELECT o.id, o.buyer_user_id, o.status, o.total_amount, o.currency, o.created_at,
                       u.email AS buyer_email, p.username AS buyer_username, p.display_name AS buyer_display_name
                FROM orders o
                INNER JOIN users u ON u.id = o.buyer_user_id
                LEFT JOIN profiles p ON p.user_id = o.buyer_user_id AND p.deleted_at IS NULL
                WHERE ' . $where . '
                ' . $order . '
                LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $params);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['buyer_user_id'] = (int) $row['buyer_user_id'];
        }

        return $rows;
    }

    /** @param array<string, mixed> $filters */
    public function countOrders(array $filters): int
    {
        [$where, $params] = $this->orderFilters($filters);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM orders o
             INNER JOIN users u ON u.id = o.buyer_user_id
             LEFT JOIN profiles p ON p.user_id = o.buyer_user_id AND p.deleted_at IS NULL
             WHERE ' . $where
        );
        $this->bind($statement, $params);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findOrderDetail(int $orderId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.buyer_user_id, o.status, o.subtotal_amount, o.total_amount, o.currency,
                    o.created_at, o.updated_at,
                    u.email AS buyer_email, u.status AS buyer_status,
                    p.username AS buyer_username, p.display_name AS buyer_display_name
             FROM orders o
             INNER JOIN users u ON u.id = o.buyer_user_id
             LEFT JOIN profiles p ON p.user_id = o.buyer_user_id AND p.deleted_at IS NULL
             WHERE o.id = :id AND o.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $orderId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['buyer_user_id'] = (int) $row['buyer_user_id'];
        $row['items'] = $this->orderItems($orderId);
        $row['payment'] = $this->orderPayment($orderId);

        return $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listReports(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->reportFilters($filters);
        $order = $this->reportOrder((string) ($filters['sort'] ?? 'newest'));
        $sql = 'SELECT r.id, r.target_type, r.target_id, r.reason_code, r.status, r.created_at, r.resolved_at,
                       r.reporter_user_id, p.username AS reporter_username, p.display_name AS reporter_display_name
                FROM reports r
                LEFT JOIN profiles p ON p.user_id = r.reporter_user_id AND p.deleted_at IS NULL
                WHERE ' . $where . '
                ' . $order . '
                LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $params);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['target_id'] = (int) $row['target_id'];
            $row['reporter_user_id'] = (int) $row['reporter_user_id'];
        }

        return $rows;
    }

    /** @param array<string, mixed> $filters */
    public function countReports(array $filters): int
    {
        [$where, $params] = $this->reportFilters($filters);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM reports r WHERE ' . $where);
        $this->bind($statement, $params);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findReportDetail(int $reportId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.reporter_user_id, r.target_type, r.target_id, r.reason_code, r.details,
                    r.status, r.resolved_at, r.resolved_by_user_id, r.created_at, r.updated_at,
                    p.username AS reporter_username, p.display_name AS reporter_display_name
             FROM reports r
             LEFT JOIN profiles p ON p.user_id = r.reporter_user_id AND p.deleted_at IS NULL
             WHERE r.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $reportId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['reporter_user_id'] = (int) $row['reporter_user_id'];
        $row['target_id'] = (int) $row['target_id'];
        $row['resolved_by_user_id'] = $row['resolved_by_user_id'] !== null ? (int) $row['resolved_by_user_id'] : null;
        $row['target'] = $this->reportTargetSummary((string) $row['target_type'], (int) $row['target_id']);

        return $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listAudit(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->auditFilters($filters);
        $order = $this->auditOrder((string) ($filters['sort'] ?? 'newest'));
        $sql = 'SELECT a.id, a.actor_user_id, a.event_type, a.entity_type, a.entity_id, a.created_at,
                       p.username AS actor_username
                FROM audit_logs a
                LEFT JOIN profiles p ON p.user_id = a.actor_user_id AND p.deleted_at IS NULL
                WHERE ' . $where . '
                ' . $order . '
                LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $params);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $row): array => $this->normalizeAuditListRow($row), $statement->fetchAll());
    }

    /** @param array<string, mixed> $filters */
    public function countAudit(array $filters): int
    {
        [$where, $params] = $this->auditFilters($filters);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM audit_logs a
             LEFT JOIN profiles p ON p.user_id = a.actor_user_id AND p.deleted_at IS NULL
             WHERE ' . $where
        );
        $this->bind($statement, $params);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findAuditDetail(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.actor_user_id, a.event_type, a.entity_type, a.entity_id, a.metadata_json, a.created_at,
                    p.username AS actor_username, p.display_name AS actor_display_name
             FROM audit_logs a
             LEFT JOIN profiles p ON p.user_id = a.actor_user_id AND p.deleted_at IS NULL
             WHERE a.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['actor_user_id'] = $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null;
        $row['entity_id'] = $row['entity_id'] !== null ? (int) $row['entity_id'] : null;
        $row['metadata'] = $this->decodeMetadata(is_string($row['metadata_json'] ?? null) ? $row['metadata_json'] : null);
        unset($row['metadata_json']);

        return $row;
    }

    /** @return list<string> */
    public function distinctAuditEventTypes(): array
    {
        $rows = $this->pdo->query('SELECT DISTINCT event_type FROM audit_logs ORDER BY event_type ASC')->fetchAll();
        $types = [];

        foreach ($rows as $row) {
            if (is_string($row['event_type'] ?? null) && $row['event_type'] !== '') {
                $types[] = $row['event_type'];
            }
        }

        return $types;
    }

    /** @return list<string> */
    public function distinctAuditEntityTypes(): array
    {
        $rows = $this->pdo->query('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type ASC')->fetchAll();
        $types = [];

        foreach ($rows as $row) {
            if (is_string($row['entity_type'] ?? null) && $row['entity_type'] !== '') {
                $types[] = $row['entity_type'];
            }
        }

        return $types;
    }

    /** @return array<string, int> */
    private function groupCounts(string $sql): array
    {
        $counts = [];

        foreach ($this->pdo->query($sql)->fetchAll() as $row) {
            $counts[(string) $row['k']] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function userFilters(array $filters): array
    {
        $where = ['u.deleted_at IS NULL'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = $this->likeContains($q);
            $where[] = '(u.email LIKE :q_email ESCAPE \'\\\\\' OR p.username LIKE :q_username ESCAPE \'\\\\\' OR p.display_name LIKE :q_display ESCAPE \'\\\\\')';
            $params['q_email'] = $like;
            $params['q_username'] = $like;
            $params['q_display'] = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['active', 'suspended', 'banned'], true)) {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }

        $verified = (string) ($filters['email_verified'] ?? '');
        if ($verified === 'verified') {
            $where[] = 'u.email_verified_at IS NOT NULL';
        } elseif ($verified === 'unverified') {
            $where[] = 'u.email_verified_at IS NULL';
        }

        $role = (string) ($filters['role'] ?? '');
        if (in_array($role, ['buyer', 'creator', 'moderator', 'admin'], true)) {
            $where[] = 'EXISTS (
                SELECT 1 FROM user_roles urf
                INNER JOIN roles rf ON rf.id = urf.role_id
                WHERE urf.user_id = u.id AND rf.name = :role_name
            )';
            $params['role_name'] = $role;
        }

        return [implode(' AND ', $where), $params];
    }

    private function userOrder(string $sort): string
    {
        return match ($sort) {
            'oldest' => 'ORDER BY u.created_at ASC, u.id ASC',
            'email_asc' => 'ORDER BY u.email ASC, u.id ASC',
            'email_desc' => 'ORDER BY u.email DESC, u.id DESC',
            'status' => 'ORDER BY u.status ASC, u.id DESC',
            default => 'ORDER BY u.created_at DESC, u.id DESC',
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function creatorFilters(array $filters): array
    {
        $where = ['cp.deleted_at IS NULL'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = $this->likeContains($q);
            $where[] = '(p.username LIKE :q_username ESCAPE \'\\\\\' OR p.display_name LIKE :q_display ESCAPE \'\\\\\' OR u.email LIKE :q_email ESCAPE \'\\\\\')';
            $params['q_username'] = $like;
            $params['q_display'] = $like;
            $params['q_email'] = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['active', 'pending', 'rejected', 'suspended'], true)) {
            $where[] = 'cp.status = :status';
            $params['status'] = $status;
        }

        return [implode(' AND ', $where), $params];
    }

    private function creatorOrder(string $sort): string
    {
        return match ($sort) {
            'oldest' => 'ORDER BY cp.created_at ASC, cp.id ASC',
            'status' => 'ORDER BY cp.status ASC, cp.id DESC',
            default => 'ORDER BY cp.created_at DESC, cp.id DESC',
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function listingFilters(array $filters): array
    {
        $where = ['l.deleted_at IS NULL'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = $this->likeContains($q);
            $where[] = '(l.title LIKE :q_title ESCAPE \'\\\\\' OR p.username LIKE :q_username ESCAPE \'\\\\\')';
            $params['q_title'] = $like;
            $params['q_username'] = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['draft', 'pending_review', 'published', 'rejected', 'suspended'], true)) {
            $where[] = 'l.status = :status';
            $params['status'] = $status;
        }

        $visibility = (string) ($filters['visibility'] ?? '');
        if (in_array($visibility, ['public', 'private', 'unlisted'], true)) {
            $where[] = 'l.visibility = :visibility';
            $params['visibility'] = $visibility;
        }

        $type = (string) ($filters['listing_type'] ?? ($filters['type'] ?? ''));
        if (in_array($type, ['physical_product', 'digital_content', 'service', 'bundle'], true)) {
            $where[] = 'l.listing_type = :listing_type';
            $params['listing_type'] = $type;
        }

        $creator = trim((string) ($filters['creator'] ?? ''));
        if ($creator !== '' && ctype_digit($creator)) {
            $where[] = 'l.owner_user_id = :owner_id';
            $params['owner_id'] = (int) $creator;
        } elseif ($creator !== '') {
            $where[] = 'LOWER(p.username) = :creator_username';
            $params['creator_username'] = strtolower($creator);
        }

        return [implode(' AND ', $where), $params];
    }

    private function listingOrder(string $sort): string
    {
        return match ($sort) {
            'oldest' => 'ORDER BY l.created_at ASC, l.id ASC',
            'updated' => 'ORDER BY l.updated_at DESC, l.id DESC',
            'status' => 'ORDER BY l.status ASC, l.id DESC',
            default => 'ORDER BY l.created_at DESC, l.id DESC',
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function orderFilters(array $filters): array
    {
        $where = ['o.deleted_at IS NULL'];
        $params = [];

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['pending', 'paid', 'completed'], true)) {
            $where[] = 'o.status = :status';
            $params['status'] = $status;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = $this->likeContains($q);
            $where[] = '(u.email LIKE :q_email ESCAPE \'\\\\\' OR p.username LIKE :q_username ESCAPE \'\\\\\')';
            $params['q_email'] = $like;
            $params['q_username'] = $like;
        }

        $from = $this->validDate((string) ($filters['date_from'] ?? ''));
        if ($from !== null) {
            $where[] = 'o.created_at >= :date_from';
            $params['date_from'] = $from . ' 00:00:00';
        }

        $to = $this->validDate((string) ($filters['date_to'] ?? ''));
        if ($to !== null) {
            $where[] = 'o.created_at <= :date_to';
            $params['date_to'] = $to . ' 23:59:59';
        }

        return [implode(' AND ', $where), $params];
    }

    private function orderOrder(string $sort): string
    {
        return match ($sort) {
            'oldest' => 'ORDER BY o.created_at ASC, o.id ASC',
            'status' => 'ORDER BY o.status ASC, o.id DESC',
            default => 'ORDER BY o.created_at DESC, o.id DESC',
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function reportFilters(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['open', 'in_review', 'resolved', 'dismissed'], true)) {
            $where[] = 'r.status = :status';
            $params['status'] = $status;
        }

        return [implode(' AND ', $where), $params];
    }

    private function reportOrder(string $sort): string
    {
        return match ($sort) {
            'oldest' => 'ORDER BY r.created_at ASC, r.id ASC',
            'status' => 'ORDER BY r.status ASC, r.id DESC',
            default => 'ORDER BY r.created_at DESC, r.id DESC',
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function auditFilters(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        $event = trim((string) ($filters['event_type'] ?? ''));
        if ($event !== '' && preg_match('/\A[a-z0-9_]{1,60}\z/', $event) === 1) {
            $where[] = 'a.event_type = :event_type';
            $params['event_type'] = $event;
        }

        $entity = trim((string) ($filters['entity_type'] ?? ''));
        if ($entity !== '' && preg_match('/\A[a-z0-9_]{1,40}\z/', $entity) === 1) {
            $where[] = 'a.entity_type = :entity_type';
            $params['entity_type'] = $entity;
        }

        $actor = trim((string) ($filters['actor'] ?? ''));
        if ($actor !== '' && ctype_digit($actor)) {
            $where[] = 'a.actor_user_id = :actor_id';
            $params['actor_id'] = (int) $actor;
        } elseif ($actor !== '') {
            $where[] = 'p.username = :actor_username';
            $params['actor_username'] = $actor;
        }

        $from = $this->validDate((string) ($filters['date_from'] ?? ''));
        if ($from !== null) {
            $where[] = 'a.created_at >= :date_from';
            $params['date_from'] = $from . ' 00:00:00';
        }

        $to = $this->validDate((string) ($filters['date_to'] ?? ''));
        if ($to !== null) {
            $where[] = 'a.created_at <= :date_to';
            $params['date_to'] = $to . ' 23:59:59';
        }

        return [implode(' AND ', $where), $params];
    }

    private function auditOrder(string $sort): string
    {
        return match ($sort) {
            'oldest' => 'ORDER BY a.created_at ASC, a.id ASC',
            default => 'ORDER BY a.created_at DESC, a.id DESC',
        };
    }

    /** @param array<string, mixed> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            if (is_int($value)) {
                $statement->bindValue(':' . $name, $value, PDO::PARAM_INT);
            } else {
                $statement->bindValue(':' . $name, $value);
            }
        }
    }

    private function likeContains(string $value): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        return '%' . $escaped . '%';
    }

    private function validDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value
            ? $value
            : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeUserListRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['email_verified'] = is_string($row['email_verified_at'] ?? null);
        $row['roles'] = $this->splitRoles($row['roles'] ?? null);
        unset($row['email_verified_at']);

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeUserDetail(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['email_verified'] = is_string($row['email_verified_at'] ?? null);
        $row['roles'] = $this->splitRoles($row['roles'] ?? null);
        $row['deleted_at'] = is_string($row['deleted_at'] ?? null) ? $row['deleted_at'] : null;

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeCreatorListRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['listing_count'] = (int) $row['listing_count'];
        $row['has_creator_role'] = (int) $row['has_creator_role'] === 1;
        $row['email_verified'] = is_string($row['email_verified_at'] ?? null);

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeAuditListRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['actor_user_id'] = $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null;
        $row['entity_id'] = $row['entity_id'] !== null ? (int) $row['entity_id'] : null;

        return $row;
    }

    /** @return list<string> */
    private function splitRoles(mixed $roles): array
    {
        if (!is_string($roles) || $roles === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $roles), static fn (string $role): bool => $role !== ''));
    }

    /** @return list<string> */
    private function rolesForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.name
             FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id
             ORDER BY r.name ASC'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(static fn (array $row): string => (string) $row['name'], $statement->fetchAll());
    }

    /** @return array<string, int> */
    private function listingStatsForOwner(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT status AS k, COUNT(*) AS c
             FROM listings
             WHERE owner_user_id = :user_id AND deleted_at IS NULL
             GROUP BY status'
        );
        $statement->execute(['user_id' => $userId]);

        $stats = ['draft' => 0, 'pending_review' => 0, 'published' => 0, 'rejected' => 0, 'suspended' => 0, 'total' => 0];
        foreach ($statement->fetchAll() as $row) {
            $key = (string) $row['k'];
            $count = (int) $row['c'];
            $stats[$key] = $count;
            $stats['total'] += $count;
        }

        return $stats;
    }

    /** @return array<string, mixed>|null */
    private function latestAgeVerification(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status, method, verified_at, expires_at, created_at
             FROM age_verifications
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['id'] = (int) $row['id'];

        return $row;
    }

    /** @return array<string, int> */
    private function listingActivityCounts(int $listingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM order_items WHERE listing_id = :oid) AS orders,
                (SELECT COUNT(*) FROM favorites WHERE listing_id = :fid) AS favorites,
                (SELECT COUNT(*) FROM reports WHERE target_type = \'listing\' AND target_id = :rid) AS reports'
        );
        $statement->execute([
            'oid' => $listingId,
            'fid' => $listingId,
            'rid' => $listingId,
        ]);
        $row = $statement->fetch();

        return [
            'orders' => (int) ($row['orders'] ?? 0),
            'favorites' => (int) ($row['favorites'] ?? 0),
            'reports' => (int) ($row['reports'] ?? 0),
        ];
    }

    /** @return list<array{id: int, name: string, slug: string}> */
    private function listingCategories(int $listingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.name, c.slug
             FROM listing_categories lc
             INNER JOIN categories c ON c.id = lc.category_id
             WHERE lc.listing_id = :listing_id
             ORDER BY c.name ASC'
        );
        $statement->execute(['listing_id' => $listingId]);
        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function listingMediaSummary(int $listingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT lm.media_file_id, lm.usage_type, mf.media_type, mf.visibility, mf.mime_type, mf.status, mf.size_bytes
             FROM listing_media lm
             INNER JOIN media_files mf ON mf.id = lm.media_file_id
             WHERE lm.listing_id = :listing_id
                AND mf.deleted_at IS NULL
             ORDER BY lm.sort_order ASC, lm.id ASC'
        );
        $statement->execute(['listing_id' => $listingId]);
        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'media_file_id' => (int) $row['media_file_id'],
                'usage_type' => (string) $row['usage_type'],
                'media_type' => (string) $row['media_type'],
                'visibility' => (string) $row['visibility'],
                'mime_type' => (string) ($row['mime_type'] ?? ''),
                'status' => (string) $row['status'],
                'size_bytes' => $row['size_bytes'] !== null ? (int) $row['size_bytes'] : null,
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function orderItems(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT oi.id, oi.listing_id, oi.seller_user_id, oi.title_snapshot, oi.unit_price,
                    oi.quantity, oi.total_amount, oi.currency, oi.status,
                    p.username AS seller_username, p.display_name AS seller_display_name
             FROM order_items oi
             LEFT JOIN profiles p ON p.user_id = oi.seller_user_id AND p.deleted_at IS NULL
             WHERE oi.order_id = :order_id
             ORDER BY oi.id ASC'
        );
        $statement->execute(['order_id' => $orderId]);
        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'listing_id' => (int) $row['listing_id'],
                'seller_user_id' => (int) $row['seller_user_id'],
                'title_snapshot' => (string) $row['title_snapshot'],
                'unit_price' => (string) $row['unit_price'],
                'quantity' => (int) $row['quantity'],
                'total_amount' => (string) $row['total_amount'],
                'currency' => (string) $row['currency'],
                'status' => (string) $row['status'],
                'seller_username' => is_string($row['seller_username'] ?? null) ? $row['seller_username'] : '',
                'seller_display_name' => is_string($row['seller_display_name'] ?? null) ? $row['seller_display_name'] : '',
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function orderPayment(int $orderId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, provider, external_id, amount, currency, status, paid_at, created_at
             FROM payments
             WHERE order_id = :order_id
             ORDER BY id ASC
             LIMIT 1'
        );
        $statement->execute(['order_id' => $orderId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['id'] = (int) $row['id'];

        return $row;
    }

    /** @return array<string, mixed> */
    private function reportTargetSummary(string $type, int $targetId): array
    {
        if ($type === 'listing') {
            $statement = $this->pdo->prepare(
                'SELECT id, title, status, owner_user_id FROM listings WHERE id = :id LIMIT 1'
            );
            $statement->execute(['id' => $targetId]);
            $row = $statement->fetch();

            return is_array($row)
                ? ['type' => 'listing', 'id' => (int) $row['id'], 'title' => (string) $row['title'], 'status' => (string) $row['status']]
                : ['type' => 'listing', 'id' => $targetId, 'title' => null, 'status' => null];
        }

        if ($type === 'user') {
            $statement = $this->pdo->prepare(
                'SELECT u.id, u.status, p.username FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = :id LIMIT 1'
            );
            $statement->execute(['id' => $targetId]);
            $row = $statement->fetch();

            return is_array($row)
                ? ['type' => 'user', 'id' => (int) $row['id'], 'username' => (string) ($row['username'] ?? ''), 'status' => (string) $row['status']]
                : ['type' => 'user', 'id' => $targetId, 'username' => null, 'status' => null];
        }

        return ['type' => $type, 'id' => $targetId];
    }

    /** @return array<string, mixed> */
    private function decodeMetadata(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return ['_raw' => $json];
        }

        return $this->redactMetadata($decoded);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function redactMetadata(array $data): array
    {
        $sensitive = [
            'password', 'password_hash', 'token', 'raw_token', 'reset_token', 'verification_token',
            'secret', 'storage_key', 'cookie', 'session', 'csrf', '_csrf', 'pan', 'cvv', 'cvc', 'iban',
            'mail_password', 'smtp_password',
        ];
        $clean = [];

        foreach ($data as $key => $value) {
            $name = strtolower((string) $key);

            if (in_array($name, $sensitive, true) || str_contains($name, 'token') || str_contains($name, 'password')) {
                $clean[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $clean[$key] = $this->redactMetadata($value);
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
