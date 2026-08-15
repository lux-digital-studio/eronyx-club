<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\CreatorApplicationRepository;
use RuntimeException;
use Throwable;

final class CreatorApplicationService
{
    private \PDO $pdo;
    private CreatorApplicationRepository $applications;

    public function __construct(?\PDO $pdo = null, ?CreatorApplicationRepository $applications = null)
    {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->applications = $applications ?? new CreatorApplicationRepository($this->pdo);
    }

    /** @return array<string, mixed>|null */
    public function findForUser(int $userId): ?array
    {
        return $this->applications->findByUserId($userId);
    }

    /** @return list<array<string, mixed>> */
    public function pendingApplications(): array
    {
        return $this->applications->findPendingApplications();
    }

    /** @return array<string, mixed>|null */
    public function pendingApplication(int $applicationId): ?array
    {
        return $this->applications->findPendingById($applicationId);
    }

    public function apply(int $userId): void
    {
        if ($this->applications->findActiveUser($userId) === null) {
            throw new RuntimeException('forbidden');
        }

        $application = $this->applications->findByUserId($userId);

        if ($application !== null && $application['deleted_at'] !== null) {
            throw new RuntimeException('forbidden');
        }

        if ($application !== null && in_array($application['status'], ['active', 'suspended'], true)) {
            throw new RuntimeException('forbidden');
        }

        if ($application !== null && $application['status'] === 'pending') {
            return;
        }

        try {
            $this->pdo->beginTransaction();

            if ($application === null) {
                $this->applications->createPendingApplication($userId);
            } elseif ($application['status'] === 'rejected') {
                if (!$this->applications->reapply((int) $application['id'])) {
                    throw new RuntimeException('forbidden');
                }
            }

            if (!$this->applications->hasPendingSelfDeclaration($userId)) {
                $this->applications->createPendingAgeVerification($userId);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function approve(int $applicationId, int $reviewerUserId): bool
    {
        return $this->transition($applicationId, $reviewerUserId, true);
    }

    public function reject(int $applicationId, int $reviewerUserId): bool
    {
        return $this->transition($applicationId, $reviewerUserId, false);
    }

    private function transition(int $applicationId, int $reviewerUserId, bool $approve): bool
    {
        if ($this->applications->findActiveUser($reviewerUserId) === null) {
            throw new RuntimeException('forbidden');
        }

        $application = $this->applications->findPendingById($applicationId);

        if ($application === null || $this->applications->findActiveUser((int) $application['user_id']) === null) {
            return false;
        }

        try {
            $this->pdo->beginTransaction();

            $updated = $approve
                ? $this->applications->approveApplication($applicationId)
                : $this->applications->rejectApplication($applicationId);

            if (!$updated) {
                $this->pdo->rollBack();

                return false;
            }

            if ($approve) {
                $this->applications->verifyPendingAgeDeclaration((int) $application['user_id']);
                $this->applications->assignCreatorRole((int) $application['user_id']);
            } else {
                $this->applications->rejectPendingAgeDeclaration((int) $application['user_id']);
            }

            $this->pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function hasCreatorAccess(int $userId): bool
    {
        $application = $this->applications->findByUserId($userId);

        return $application !== null
            && $application['status'] === 'active'
            && $application['deleted_at'] === null
            && $this->applications->hasCreatorRole($userId);
    }
}
