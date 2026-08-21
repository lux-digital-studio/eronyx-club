<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\AdminRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\ModerationActionRepository;
use App\Repositories\UserRepository;
use RuntimeException;
use Throwable;

final class AdminService
{
    public const PER_PAGE = AdminRepository::PER_PAGE;
    public const SORTS_USERS = ['newest', 'oldest', 'email_asc', 'email_desc', 'status'];
    public const SORTS_CREATORS = ['newest', 'oldest', 'status'];
    public const SORTS_LISTINGS = ['newest', 'oldest', 'updated', 'status'];
    public const SORTS_ORDERS = ['newest', 'oldest', 'status'];
    public const SORTS_REPORTS = ['newest', 'oldest', 'status'];
    public const SORTS_AUDIT = ['newest', 'oldest'];

    private \PDO $pdo;
    private AdminRepository $admin;
    private UserRepository $users;
    private AuditLogRepository $audit;
    private ModerationActionRepository $moderation;

    public function __construct(
        ?\PDO $pdo = null,
        ?AdminRepository $admin = null,
        ?UserRepository $users = null,
        ?AuditLogRepository $audit = null,
        ?ModerationActionRepository $moderation = null
    ) {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->admin = $admin ?? new AdminRepository($this->pdo);
        $this->users = $users ?? new UserRepository($this->pdo);
        $this->audit = $audit ?? new AuditLogRepository($this->pdo);
        $this->moderation = $moderation ?? new ModerationActionRepository($this->pdo);
    }

    public function dashboard(): array
    {
        $counts = $this->admin->dashboardCounts();

        return [
            'counts' => $counts,
            'recentAudit' => $this->admin->recentAudit(8),
        ];
    }

    /** @param array<string, mixed> $query */
    public function users(array $query): array
    {
        $filters = $this->userFilters($query);
        $total = $this->admin->countUsers($filters);
        $page = $this->page($query['page'] ?? 1, $total);

        return $this->pageResult(
            $this->admin->listUsers($filters, self::PER_PAGE, $this->offset($page)),
            $filters,
            $total,
            $page
        );
    }

    public function userDetail(int $userId): array
    {
        $user = $this->admin->findUserDetail($userId);

        if ($user === null) {
            throw new RuntimeException('not_found');
        }

        $user['counts'] = $this->admin->userActivityCounts($userId);
        $user['creator_profile'] = $this->admin->findCreatorDetail($userId);
        $user['consents'] = (new UserConsentService($this->pdo))->findForUser($userId);

        return $user;
    }

    /**
     * @return array{ok: bool, reason?: string}
     */
    public function suspendUser(int $actorUserId, int $targetUserId): array
    {
        if ($actorUserId === $targetUserId) {
            return ['ok' => false, 'reason' => 'self'];
        }

        $target = $this->users->findAuthorizationContext($targetUserId);

        if ($target === null || $target['deleted_at'] !== null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        if ($this->isPrivileged($target['roles'])) {
            return ['ok' => false, 'reason' => 'privileged'];
        }

        if ($target['status'] !== 'active') {
            return ['ok' => false, 'reason' => 'not_active'];
        }

        try {
            $this->pdo->beginTransaction();
            $updated = $this->users->updateStatusIf($targetUserId, 'active', 'suspended');

            if (!$updated) {
                $this->pdo->rollBack();

                return ['ok' => false, 'reason' => 'not_active'];
            }

            $this->users->incrementSessionVersion($targetUserId);
            $this->audit->record($actorUserId, 'user_suspended', 'user', $targetUserId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, reason?: string}
     */
    public function reactivateUser(int $actorUserId, int $targetUserId): array
    {
        if ($actorUserId === $targetUserId) {
            return ['ok' => false, 'reason' => 'self'];
        }

        $target = $this->users->findAuthorizationContext($targetUserId);

        if ($target === null || $target['deleted_at'] !== null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        if ($this->isPrivileged($target['roles'])) {
            return ['ok' => false, 'reason' => 'privileged'];
        }

        if ($target['status'] !== 'suspended') {
            return ['ok' => false, 'reason' => 'not_suspended'];
        }

        try {
            $this->pdo->beginTransaction();
            $updated = $this->users->updateStatusIf($targetUserId, 'suspended', 'active');

            if (!$updated) {
                $this->pdo->rollBack();

                return ['ok' => false, 'reason' => 'not_suspended'];
            }

            $this->audit->record($actorUserId, 'user_reactivated', 'user', $targetUserId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return ['ok' => true];
    }

    /** @param array<string, mixed> $query */
    public function creators(array $query): array
    {
        $filters = [
            'q' => trim((string) ($query['q'] ?? '')),
            'status' => (string) ($query['status'] ?? ''),
            'sort' => $this->sort((string) ($query['sort'] ?? 'newest'), self::SORTS_CREATORS),
        ];
        $total = $this->admin->countCreators($filters);
        $page = $this->page($query['page'] ?? 1, $total);

        return $this->pageResult(
            $this->admin->listCreators($filters, self::PER_PAGE, $this->offset($page)),
            $filters,
            $total,
            $page
        );
    }

    public function creatorDetail(int $userId): array
    {
        $creator = $this->admin->findCreatorDetail($userId);

        if ($creator === null) {
            throw new RuntimeException('not_found');
        }

        $creator['moderation_actions'] = $this->moderation->findByTarget('user', $userId);

        return $creator;
    }

    /** @param array<string, mixed> $query */
    public function listings(array $query): array
    {
        $filters = [
            'q' => trim((string) ($query['q'] ?? '')),
            'status' => (string) ($query['status'] ?? ''),
            'visibility' => (string) ($query['visibility'] ?? ''),
            'listing_type' => (string) ($query['listing_type'] ?? ($query['type'] ?? '')),
            'creator' => trim((string) ($query['creator'] ?? '')),
            'sort' => $this->sort((string) ($query['sort'] ?? 'newest'), self::SORTS_LISTINGS),
        ];
        $total = $this->admin->countListings($filters);
        $page = $this->page($query['page'] ?? 1, $total);

        return $this->pageResult(
            $this->admin->listListings($filters, self::PER_PAGE, $this->offset($page)),
            $filters,
            $total,
            $page
        );
    }

    public function listingDetail(int $listingId): array
    {
        $listing = $this->admin->findListingDetail($listingId);

        if ($listing === null) {
            throw new RuntimeException('not_found');
        }

        $listing['moderation_actions'] = $this->moderation->findByTarget('listing', $listingId);

        return $listing;
    }

    /** @param array<string, mixed> $query */
    public function orders(array $query): array
    {
        $filters = [
            'q' => trim((string) ($query['q'] ?? '')),
            'status' => (string) ($query['status'] ?? ''),
            'date_from' => trim((string) ($query['date_from'] ?? '')),
            'date_to' => trim((string) ($query['date_to'] ?? '')),
            'sort' => $this->sort((string) ($query['sort'] ?? 'newest'), self::SORTS_ORDERS),
        ];
        $total = $this->admin->countOrders($filters);
        $page = $this->page($query['page'] ?? 1, $total);

        return $this->pageResult(
            $this->admin->listOrders($filters, self::PER_PAGE, $this->offset($page)),
            $filters,
            $total,
            $page
        );
    }

    public function orderDetail(int $orderId): array
    {
        $order = $this->admin->findOrderDetail($orderId);

        if ($order === null) {
            throw new RuntimeException('not_found');
        }

        return $order;
    }

    /** @param array<string, mixed> $query */
    public function reports(array $query): array
    {
        $filters = [
            'status' => (string) ($query['status'] ?? ''),
            'sort' => $this->sort((string) ($query['sort'] ?? 'newest'), self::SORTS_REPORTS),
        ];
        $total = $this->admin->countReports($filters);
        $page = $this->page($query['page'] ?? 1, $total);

        return $this->pageResult(
            $this->admin->listReports($filters, self::PER_PAGE, $this->offset($page)),
            $filters,
            $total,
            $page
        );
    }

    public function reportDetail(int $reportId): array
    {
        $report = $this->admin->findReportDetail($reportId);

        if ($report === null) {
            throw new RuntimeException('not_found');
        }

        $report['moderation_actions'] = $this->moderation->findByTarget('report', $reportId);
        $report['audit'] = $this->audit->findByEntity('report', $reportId);

        return $report;
    }

    /** @param array<string, mixed> $query */
    public function audit(array $query): array
    {
        $filters = [
            'event_type' => trim((string) ($query['event_type'] ?? '')),
            'entity_type' => trim((string) ($query['entity_type'] ?? '')),
            'actor' => trim((string) ($query['actor'] ?? '')),
            'date_from' => trim((string) ($query['date_from'] ?? '')),
            'date_to' => trim((string) ($query['date_to'] ?? '')),
            'sort' => $this->sort((string) ($query['sort'] ?? 'newest'), self::SORTS_AUDIT),
        ];
        $total = $this->admin->countAudit($filters);
        $page = $this->page($query['page'] ?? 1, $total);

        return $this->pageResult(
            $this->admin->listAudit($filters, self::PER_PAGE, $this->offset($page)),
            $filters,
            $total,
            $page
        ) + [
            'eventTypes' => $this->admin->distinctAuditEventTypes(),
            'entityTypes' => $this->admin->distinctAuditEntityTypes(),
        ];
    }

    public function auditDetail(int $id): array
    {
        $row = $this->admin->findAuditDetail($id);

        if ($row === null) {
            throw new RuntimeException('not_found');
        }

        return $row;
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function userFilters(array $query): array
    {
        return [
            'q' => trim((string) ($query['q'] ?? '')),
            'status' => (string) ($query['status'] ?? ''),
            'email_verified' => (string) ($query['email_verified'] ?? ''),
            'role' => (string) ($query['role'] ?? ''),
            'sort' => $this->sort((string) ($query['sort'] ?? 'newest'), self::SORTS_USERS),
        ];
    }

    /** @param list<string> $roles */
    private function isPrivileged(array $roles): bool
    {
        return in_array('admin', $roles, true) || in_array('moderator', $roles, true);
    }

    /** @param list<string> $allowed */
    private function sort(string $sort, array $allowed): string
    {
        return in_array($sort, $allowed, true) ? $sort : 'newest';
    }

    private function page(mixed $raw, int $total): int
    {
        $page = is_numeric($raw) ? (int) $raw : 1;

        if ($page < 1) {
            $page = 1;
        }

        $lastPage = $total === 0 ? 1 : (int) ceil($total / self::PER_PAGE);

        return $page > $lastPage ? $lastPage : $page;
    }

    private function offset(int $page): int
    {
        return ($page - 1) * self::PER_PAGE;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function pageResult(array $items, array $filters, int $total, int $page): array
    {
        $lastPage = $total === 0 ? 1 : (int) ceil($total / self::PER_PAGE);

        return [
            'items' => $items,
            'filters' => $filters,
            'total' => $total,
            'perPage' => self::PER_PAGE,
            'currentPage' => $page,
            'lastPage' => $lastPage,
        ];
    }
}
