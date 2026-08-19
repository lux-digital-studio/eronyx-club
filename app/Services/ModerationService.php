<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\CreatorApplicationRepository;
use App\Repositories\ListingRepository;
use App\Repositories\MessageRepository;
use App\Repositories\ModerationActionRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\ReportRepository;
use App\Repositories\UserRepository;
use RuntimeException;
use Throwable;

final class ModerationService
{
    private const RESTORABLE_LISTING_STATUSES = ['published', 'pending_review'];

    public function __construct(
        private readonly ReportRepository $reports,
        private readonly ModerationActionRepository $actions,
        private readonly AuditLogRepository $audit,
        private readonly ListingRepository $listings,
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
        private readonly MessageRepository $messages,
        private readonly CreatorApplicationRepository $creatorProfiles
    ) {
    }

    public function openReportCount(): int
    {
        return $this->reports->countOpenReports();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queue(): array
    {
        $reports = $this->reports->findOpenReports();

        return $this->hydrateReports($reports);
    }

    /**
     * @return array{
     *   report: array<string, mixed>,
     *   reporter: array<string, mixed>|null,
     *   target: array<string, mixed>,
     *   actions: list<array<string, mixed>>,
     *   audits: list<array<string, mixed>>
     * }
     */
    public function reportDetail(int $reportId): array
    {
        $report = $this->reports->findById($reportId);

        if ($report === null) {
            throw new RuntimeException('not_found');
        }

        $hydrated = $this->hydrateReports([$report])[0];

        return [
            'report' => $hydrated,
            'reporter' => $this->safeReporter((int) $report['reporter_user_id']),
            'target' => $hydrated['target'],
            'actions' => $this->actions->findByTarget('report', $reportId),
            'audits' => $this->audit->findByEntity('report', $reportId),
        ];
    }

    public function markInReview(int $moderatorUserId, int $reportId): string
    {
        $report = $this->requireReport($reportId);
        $pdo = $this->reports->connection();

        try {
            $pdo->beginTransaction();
            $changed = $this->reports->markInReview($reportId);

            if ($changed) {
                $this->audit->record($moderatorUserId, 'report_in_review', 'report', $reportId, [
                    'from' => $report['status'],
                    'to' => 'in_review',
                ]);
            }

            $pdo->commit();

            return $changed ? 'updated' : 'noop';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function resolve(int $moderatorUserId, int $reportId): string
    {
        $report = $this->requireReport($reportId);

        if (($report['status'] ?? '') === 'resolved') {
            return 'noop';
        }

        if (($report['status'] ?? '') === 'dismissed') {
            throw new RuntimeException('forbidden');
        }

        $pdo = $this->reports->connection();

        try {
            $pdo->beginTransaction();
            $changed = $this->reports->resolve($reportId, $moderatorUserId);

            if (!$changed) {
                $pdo->rollBack();

                return 'noop';
            }

            $this->actions->createAction(
                $moderatorUserId,
                'report',
                $reportId,
                'report_resolve',
                (string) $report['reason_code']
            );
            $this->audit->record($moderatorUserId, 'report_resolved', 'report', $reportId, [
                'target_type' => $report['target_type'],
                'target_id' => $report['target_id'],
            ]);

            if ($report['target_type'] === 'message') {
                $this->actions->createAction(
                    $moderatorUserId,
                    'message',
                    (int) $report['target_id'],
                    'message_flag',
                    (string) $report['reason_code']
                );
                $this->audit->record($moderatorUserId, 'moderator_action', 'message', (int) $report['target_id'], [
                    'action_type' => 'message_flag',
                    'report_id' => $reportId,
                ]);
            }

            $pdo->commit();

            return 'updated';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function dismiss(int $moderatorUserId, int $reportId): string
    {
        $report = $this->requireReport($reportId);

        if (($report['status'] ?? '') === 'dismissed') {
            return 'noop';
        }

        if (($report['status'] ?? '') === 'resolved') {
            throw new RuntimeException('forbidden');
        }

        $pdo = $this->reports->connection();

        try {
            $pdo->beginTransaction();
            $changed = $this->reports->dismiss($reportId, $moderatorUserId);

            if (!$changed) {
                $pdo->rollBack();

                return 'noop';
            }

            $this->actions->createAction(
                $moderatorUserId,
                'report',
                $reportId,
                'report_dismiss',
                (string) $report['reason_code']
            );
            $this->audit->record($moderatorUserId, 'report_dismissed', 'report', $reportId, [
                'target_type' => $report['target_type'],
                'target_id' => $report['target_id'],
            ]);
            $pdo->commit();

            return 'updated';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function suspendListing(int $moderatorUserId, int $listingId): string
    {
        $listing = $this->listings->findById($listingId);

        if ($listing === null) {
            throw new RuntimeException('not_found');
        }

        $currentStatus = (string) $listing['status'];

        if ($currentStatus === 'suspended') {
            return 'noop';
        }

        if (!in_array($currentStatus, self::RESTORABLE_LISTING_STATUSES, true)) {
            throw new RuntimeException('forbidden');
        }

        $pdo = $this->reports->connection();

        try {
            $pdo->beginTransaction();
            $changed = $this->listings->suspendEligible($listingId, $currentStatus);

            if (!$changed) {
                $pdo->rollBack();

                return 'noop';
            }

            $this->actions->createAction(
                $moderatorUserId,
                'listing',
                $listingId,
                'listing_suspend',
                null,
                null,
                $currentStatus
            );
            $this->audit->record($moderatorUserId, 'listing_suspended', 'listing', $listingId, [
                'previous_status' => $currentStatus,
            ]);
            $pdo->commit();

            return 'updated';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function restoreListing(int $moderatorUserId, int $listingId): string
    {
        $listing = $this->listings->findById($listingId);

        if ($listing === null) {
            throw new RuntimeException('not_found');
        }

        if ((string) $listing['status'] !== 'suspended') {
            return 'noop';
        }

        $lastSuspend = $this->actions->findLatestByTargetAndType('listing', $listingId, 'listing_suspend');
        $previousStatus = is_array($lastSuspend) ? (string) ($lastSuspend['previous_status'] ?? '') : '';

        if (!in_array($previousStatus, self::RESTORABLE_LISTING_STATUSES, true)) {
            throw new RuntimeException('forbidden');
        }

        $pdo = $this->reports->connection();

        try {
            $pdo->beginTransaction();
            $changed = $this->listings->restoreSuspended($listingId, $previousStatus);

            if (!$changed) {
                $pdo->rollBack();

                return 'noop';
            }

            $this->actions->createAction(
                $moderatorUserId,
                'listing',
                $listingId,
                'listing_restore',
                null,
                null,
                $previousStatus
            );
            $this->audit->record($moderatorUserId, 'listing_restored', 'listing', $listingId, [
                'restored_status' => $previousStatus,
            ]);
            $pdo->commit();

            return 'updated';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function suspendCreator(int $moderatorUserId, int $userId): string
    {
        $profile = $this->creatorProfiles->findByUserId($userId);

        if ($profile === null || ($profile['deleted_at'] ?? null) !== null) {
            throw new RuntimeException('not_found');
        }

        if (($profile['status'] ?? '') === 'suspended') {
            return 'noop';
        }

        if (($profile['status'] ?? '') !== 'active') {
            throw new RuntimeException('forbidden');
        }

        $pdo = $this->reports->connection();

        try {
            $pdo->beginTransaction();
            $changed = $this->creatorProfiles->suspendActive($userId);

            if (!$changed) {
                $pdo->rollBack();

                return 'noop';
            }

            $this->actions->createAction($moderatorUserId, 'user', $userId, 'creator_suspend');
            $this->audit->record($moderatorUserId, 'creator_suspended', 'user', $userId, [
                'creator_profile_id' => $profile['id'],
            ]);
            $pdo->commit();

            return 'updated';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function restoreCreator(int $moderatorUserId, int $userId): string
    {
        $profile = $this->creatorProfiles->findByUserId($userId);

        if ($profile === null || ($profile['deleted_at'] ?? null) !== null) {
            throw new RuntimeException('not_found');
        }

        if (($profile['status'] ?? '') !== 'suspended') {
            return 'noop';
        }

        if (!$this->creatorProfiles->hasCreatorRole($userId)) {
            throw new RuntimeException('role_missing');
        }

        $pdo = $this->reports->connection();

        try {
            $pdo->beginTransaction();
            $changed = $this->creatorProfiles->restoreSuspended($userId);

            if (!$changed) {
                $pdo->rollBack();

                return 'noop';
            }

            $this->actions->createAction($moderatorUserId, 'user', $userId, 'creator_restore');
            $this->audit->record($moderatorUserId, 'creator_restored', 'user', $userId, [
                'creator_profile_id' => $profile['id'],
            ]);
            $pdo->commit();

            return 'updated';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function requireReport(int $reportId): array
    {
        $report = $this->reports->findById($reportId);

        if ($report === null) {
            throw new RuntimeException('not_found');
        }

        return $report;
    }

    /**
     * @param list<array<string, mixed>> $reports
     * @return list<array<string, mixed>>
     */
    private function hydrateReports(array $reports): array
    {
        $idsByType = ['listing' => [], 'user' => [], 'message' => []];

        foreach ($reports as $report) {
            $type = (string) $report['target_type'];

            if (isset($idsByType[$type])) {
                $idsByType[$type][] = (int) $report['target_id'];
            }
        }

        $listings = $this->listings->findSummariesByIds($idsByType['listing']);
        $users = $this->users->findSafeSummariesByIds($idsByType['user']);
        $profiles = $this->profiles->findSummariesByUserIds($idsByType['user']);
        $creatorStatuses = $this->creatorProfiles->findStatusByUserIds($idsByType['user']);
        $messages = $this->messages->findByIds($idsByType['message']);

        foreach ($reports as $index => $report) {
            $reports[$index]['target'] = $this->targetSummary(
                (string) $report['target_type'],
                (int) $report['target_id'],
                $listings,
                $users,
                $profiles,
                $creatorStatuses,
                $messages
            );
        }

        return $reports;
    }

    /**
     * @param array<int, array<string, mixed>> $listings
     * @param array<int, array<string, mixed>> $users
     * @param array<int, array<string, mixed>> $profiles
     * @param array<int, string> $creatorStatuses
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function targetSummary(
        string $type,
        int $id,
        array $listings,
        array $users,
        array $profiles,
        array $creatorStatuses,
        array $messages
    ): array {
        if ($type === 'listing') {
            $listing = $listings[$id] ?? null;

            if ($listing === null) {
                return ['available' => false, 'label' => 'Recurso no disponible'];
            }

            return [
                'available' => true,
                'label' => (string) $listing['title'],
                'listing' => $listing,
            ];
        }

        if ($type === 'user') {
            $user = $users[$id] ?? null;

            if ($user === null) {
                return ['available' => false, 'label' => 'Recurso no disponible'];
            }

            $profile = $profiles[$id] ?? null;

            return [
                'available' => true,
                'label' => is_array($profile) ? (string) $profile['display_name'] : 'Usuario',
                'username' => is_array($profile) ? (string) $profile['username'] : null,
                'creator_status' => $creatorStatuses[$id] ?? null,
            ];
        }

        $message = $messages[$id] ?? null;

        if ($message === null) {
            return ['available' => false, 'label' => 'Recurso no disponible'];
        }

        return [
            'available' => true,
            'label' => 'Mensaje',
            'message' => $message,
        ];
    }

    /** @return array<string, mixed>|null */
    private function safeReporter(int $userId): ?array
    {
        $profile = $this->profiles->findByUserId($userId);

        if ($profile === null) {
            return [
                'display_name' => 'Usuario',
                'username' => null,
            ];
        }

        return [
            'display_name' => (string) $profile['display_name'],
            'username' => (string) $profile['username'],
        ];
    }
}
