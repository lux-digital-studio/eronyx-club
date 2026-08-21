<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\AgeVerificationRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use App\Services\Verification\AgeVerificationProviderFactory;
use RuntimeException;
use Throwable;

final class AgeVerificationService
{
    private \PDO $pdo;
    private AgeVerificationRepository $verifications;
    private AuditLogRepository $audit;
    private NotificationService $notifications;

    public function __construct(
        ?\PDO $pdo = null,
        ?AgeVerificationRepository $verifications = null,
        ?AuditLogRepository $audit = null,
        ?NotificationService $notifications = null
    ) {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->verifications = $verifications ?? new AgeVerificationRepository($this->pdo);
        $this->audit = $audit ?? new AuditLogRepository($this->pdo);
        $this->notifications = $notifications ?? new NotificationService(
            new NotificationRepository($this->pdo),
            new UserRepository($this->pdo)
        );
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 2) . '/config/verification.php';

        return $config;
    }

    public function requiresVerification(): bool
    {
        return (bool) $this->config()['require_verified_for_creator_activation'];
    }

    public function canManualReview(): bool
    {
        return in_array((string) $this->config()['mode'], ['manual_review', 'self_declaration'], true);
    }

    public function startVerification(int $userId, ?int $actorUserId = null): int
    {
        $this->expireOldVerification($userId);

        if ($this->hasValidVerification($userId)) {
            $current = $this->verifications->findCurrentForUser($userId);

            return (int) ($current['id'] ?? 0);
        }

        $pending = $this->verifications->findPendingForUser($userId);

        if ($pending !== null) {
            return (int) $pending['id'];
        }

        $config = $this->config();
        $mode = (string) $config['mode'];
        $ttl = (int) $config['session_ttl'];
        $session = [
            'provider' => null,
            'provider_reference' => null,
            'provider_status' => null,
            'expires_at' => null,
        ];

        if ($mode === 'provider') {
            try {
                $provider = AgeVerificationProviderFactory::make($config);
                if ($provider !== null) {
                    $session = $provider->createSession($userId, $ttl);
                }
            } catch (RuntimeException) {
                $session = [
                    'provider' => null,
                    'provider_reference' => null,
                    'provider_status' => 'unconfigured',
                    'expires_at' => null,
                ];
            }
        }

        $sessionExpires = $session['expires_at'] ?? (new \DateTimeImmutable('+' . $ttl . ' seconds'))->format('Y-m-d H:i:s');

        $id = $this->verifications->createVerification($userId, [
            'status' => 'pending',
            'method' => $mode,
            'provider' => $session['provider'] ?? ($mode === 'provider' ? (string) $config['provider'] : null),
            'provider_reference' => $session['provider_reference'] ?? null,
            'provider_status' => $session['provider_status'] ?? 'pending',
            'provider_session_expires_at' => $sessionExpires,
            'metadata' => ['source' => $mode],
        ]);

        $this->audit->record($actorUserId ?? $userId, 'age_verification_started', 'age_verification', $id, [
            'method' => $mode,
        ]);

        return $id;
    }

    public function reviewManual(int $targetUserId, int $moderatorUserId, bool $approve, string $rejectionCode = 'age_not_confirmed'): bool
    {
        if (!$this->canManualReview()) {
            return false;
        }

        $this->expireOldVerification($targetUserId);
        $pending = $this->verifications->findPendingForUser($targetUserId);

        if ($pending === null) {
            return false;
        }

        if ($approve) {
            $ok = $this->verifications->markVerified((int) $pending['id'], $moderatorUserId, 'verified');

            if ($ok) {
                $this->audit->record($moderatorUserId, 'age_verification_verified', 'age_verification', (int) $pending['id'], [
                    'method' => (string) $pending['method'],
                ]);
                $this->notify($targetUserId, $moderatorUserId, (int) $pending['id'], true);
            }

            return $ok;
        }

        $ok = $this->verifications->markRejected((int) $pending['id'], $rejectionCode, $moderatorUserId, 'rejected');

        if ($ok) {
            $this->audit->record($moderatorUserId, 'age_verification_rejected', 'age_verification', (int) $pending['id'], [
                'method' => (string) $pending['method'],
                'rejection_code' => in_array($rejectionCode, AgeVerificationRepository::REJECTION_CODES, true)
                    ? $rejectionCode
                    : 'other',
            ]);
            $this->notify($targetUserId, $moderatorUserId, (int) $pending['id'], false);
        }

        return $ok;
    }

    public function markProviderResult(int $verificationId, string $status, ?int $actorUserId = null): bool
    {
        $config = $this->config();

        if ((string) $config['mode'] !== 'provider') {
            return false;
        }

        $row = $this->verifications->findById($verificationId);

        if ($row === null || (string) $row['status'] !== 'pending') {
            return false;
        }

        $ok = match ($status) {
            'verified' => $this->verifications->markVerified($verificationId, $actorUserId, 'verified'),
            'rejected' => $this->verifications->markRejected($verificationId, 'verification_failed', $actorUserId, 'rejected'),
            'expired' => $this->verifications->markExpired($verificationId),
            default => false,
        };

        if (!$ok) {
            return false;
        }

        $event = match ($status) {
            'verified' => 'age_verification_verified',
            'rejected' => 'age_verification_rejected',
            default => 'age_verification_expired',
        };
        $this->audit->record($actorUserId, $event, 'age_verification', $verificationId, [
            'method' => 'provider',
        ]);

        if ($status === 'verified' || $status === 'rejected') {
            $this->notify((int) $row['user_id'], $actorUserId, $verificationId, $status === 'verified');
        }

        return true;
    }

    public function hasValidVerification(int $userId): bool
    {
        $this->expireOldVerification($userId);

        return $this->verifications->hasValidVerification($userId);
    }

    /** @return list<int> */
    public function expireOldVerification(int $userId): array
    {
        $ids = $this->verifications->expireDuePendingForUser($userId);

        foreach ($ids as $id) {
            $this->audit->record(null, 'age_verification_expired', 'age_verification', $id, [
                'method' => (string) $this->config()['mode'],
            ]);
        }

        return $ids;
    }

    public function cancelPending(int $userId, string $rejectionCode = 'policy_restriction'): bool
    {
        $pending = $this->verifications->findPendingForUser($userId);

        if ($pending === null) {
            return false;
        }

        return $this->verifications->markCancelled((int) $pending['id'], $rejectionCode);
    }

    /** @return array<string, mixed>|null */
    public function publicSummary(int $userId): ?array
    {
        $this->expireOldVerification($userId);
        $row = $this->verifications->findCurrentForUser($userId);

        if ($row === null) {
            return null;
        }

        return [
            'status' => (string) $row['status'],
            'method' => (string) $row['method'],
            'verified_at' => $row['verified_at'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function adminSummary(int $userId): ?array
    {
        $this->expireOldVerification($userId);

        return $this->verifications->adminSummaryForUser($userId);
    }

    /** @return list<array<string, mixed>> */
    public function history(int $userId): array
    {
        return $this->verifications->findHistoryForUser($userId);
    }

    private function notify(int $userId, ?int $actorUserId, int $verificationId, bool $verified): void
    {
        if ($verified) {
            $this->notifications->notify(
                $userId,
                'age_verification_verified',
                'Verificación de edad confirmada',
                'Tu verificación de mayoría de edad ha sido confirmada.',
                $actorUserId,
                'age_verification',
                $verificationId,
                '/account/creator/status',
                'age_verification:' . $verificationId . ':verified'
            );

            return;
        }

        $this->notifications->notify(
            $userId,
            'age_verification_rejected',
            'Verificación de edad rechazada',
            'Tu verificación de mayoría de edad ha sido rechazada.',
            $actorUserId,
            'age_verification',
            $verificationId,
            '/account/creator/status',
            'age_verification:' . $verificationId . ':rejected'
        );
    }
}
