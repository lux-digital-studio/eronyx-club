<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\ListingRepository;
use App\Repositories\MessageRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\ReportRepository;
use App\Repositories\UserRepository;
use PDOException;
use RuntimeException;
use Throwable;

final class ReportService
{
    public const TARGET_LISTING = 'listing';
    public const TARGET_USER = 'user';
    public const TARGET_MESSAGE = 'message';

    public function __construct(
        private readonly ReportRepository $reports,
        private readonly AuditLogRepository $audit,
        private readonly ListingRepository $listings,
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
        private readonly MessageRepository $messages,
        private readonly ConversationRepository $conversations
    ) {
    }

    public function reportListing(int $reporterUserId, int $listingId, string $reasonCode, ?string $details): int
    {
        $listing = $this->listings->findById($listingId);

        if ($listing === null || !$this->isReportableListing($listing)) {
            throw new RuntimeException('not_found');
        }

        if ((int) $listing['owner_user_id'] === $reporterUserId) {
            throw new RuntimeException('forbidden');
        }

        return $this->createReport($reporterUserId, self::TARGET_LISTING, $listingId, $reasonCode, $details);
    }

    public function reportUser(int $reporterUserId, int $targetUserId, string $reasonCode, ?string $details): int
    {
        if ($reporterUserId === $targetUserId) {
            throw new RuntimeException('forbidden');
        }

        $user = $this->users->findExistingById($targetUserId);

        if ($user === null || $user['deleted_at'] !== null) {
            throw new RuntimeException('not_found');
        }

        return $this->createReport($reporterUserId, self::TARGET_USER, $targetUserId, $reasonCode, $details);
    }

    public function reportMessage(int $reporterUserId, int $messageId, string $reasonCode, ?string $details): int
    {
        $message = $this->messages->findById($messageId);

        if ($message === null) {
            throw new RuntimeException('not_found');
        }

        $conversationId = (int) $message['conversation_id'];

        if (!$this->conversations->isParticipant($conversationId, $reporterUserId)) {
            throw new RuntimeException('forbidden');
        }

        if ((int) $message['sender_user_id'] === $reporterUserId) {
            throw new RuntimeException('forbidden');
        }

        return $this->createReport($reporterUserId, self::TARGET_MESSAGE, $messageId, $reasonCode, $details);
    }

    /** @return array<string, mixed> */
    public function listingFormContext(int $reporterUserId, int $listingId): array
    {
        $listing = $this->listings->findById($listingId);

        if ($listing === null || !$this->isReportableListing($listing)) {
            throw new RuntimeException('not_found');
        }

        if ((int) $listing['owner_user_id'] === $reporterUserId) {
            throw new RuntimeException('forbidden');
        }

        return [
            'target_type' => self::TARGET_LISTING,
            'target_id' => $listingId,
            'title' => (string) $listing['title'],
            'cancel_url_path' => '/marketplace/' . $listing['slug'],
        ];
    }

    /** @return array<string, mixed> */
    public function userFormContext(int $reporterUserId, int $targetUserId): array
    {
        if ($reporterUserId === $targetUserId) {
            throw new RuntimeException('forbidden');
        }

        $user = $this->users->findExistingById($targetUserId);

        if ($user === null || $user['deleted_at'] !== null) {
            throw new RuntimeException('not_found');
        }

        $profile = $this->profiles->findByUserId($targetUserId);
        $username = is_array($profile) ? (string) ($profile['username'] ?? '') : '';
        $displayName = is_array($profile) ? (string) ($profile['display_name'] ?? '') : 'Usuario';

        return [
            'target_type' => self::TARGET_USER,
            'target_id' => $targetUserId,
            'title' => $displayName !== '' ? $displayName : 'Usuario',
            'cancel_url_path' => $username !== '' ? '/creator/' . $username : '/account',
        ];
    }

    /** @return array<string, mixed> */
    public function messageFormContext(int $reporterUserId, int $messageId): array
    {
        $message = $this->messages->findById($messageId);

        if ($message === null) {
            throw new RuntimeException('not_found');
        }

        if (!$this->conversations->isParticipant((int) $message['conversation_id'], $reporterUserId)) {
            throw new RuntimeException('forbidden');
        }

        if ((int) $message['sender_user_id'] === $reporterUserId) {
            throw new RuntimeException('forbidden');
        }

        return [
            'target_type' => self::TARGET_MESSAGE,
            'target_id' => $messageId,
            'title' => 'Mensaje',
            'cancel_url_path' => '/account/messages/' . $message['conversation_id'],
        ];
    }

    private function createReport(
        int $reporterUserId,
        string $targetType,
        int $targetId,
        string $reasonCode,
        ?string $details
    ): int {
        $existing = $this->reports->findOpenDuplicate($reporterUserId, $targetType, $targetId);

        if ($existing !== null) {
            throw new RuntimeException('duplicate');
        }

        $pdo = $this->reports->connection();

        try {
            $pdo->beginTransaction();
            $reportId = $this->reports->create($reporterUserId, $targetType, $targetId, $reasonCode, $details);
            $this->audit->record($reporterUserId, 'report_created', 'report', $reportId, [
                'target_type' => $targetType,
                'target_id' => $targetId,
                'reason_code' => $reasonCode,
            ]);
            $pdo->commit();

            return $reportId;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($this->reports->isDuplicateKey($exception)) {
                throw new RuntimeException('duplicate');
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $listing */
    private function isReportableListing(array $listing): bool
    {
        return ($listing['status'] ?? '') === 'published'
            && ($listing['published_at'] ?? null) !== null
            && ($listing['published_at'] ?? '') !== ''
            && in_array($listing['visibility'] ?? '', ['public', 'unlisted'], true);
    }
}
