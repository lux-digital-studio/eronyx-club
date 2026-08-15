<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\ListingRepository;
use App\Repositories\PrivateContentAccessRepository;
use App\Repositories\UserRepository;
use RuntimeException;

final class PrivateContentAccessService
{
    private PrivateContentAccessRepository $access;
    private ListingRepository $listings;
    private UserRepository $users;

    public function __construct(
        ?PrivateContentAccessRepository $access = null,
        ?ListingRepository $listings = null,
        ?UserRepository $users = null,
        ?\PDO $pdo = null
    ) {
        $pdo ??= (new Database())->connection();
        $this->access = $access ?? new PrivateContentAccessRepository($pdo);
        $this->listings = $listings ?? new ListingRepository($pdo);
        $this->users = $users ?? new UserRepository($pdo);
    }

    public function canAccessListingPrivateContent(?int $userId, int $listingId): bool
    {
        if ($userId === null || !$this->isActiveUser($userId)) {
            return false;
        }

        $listing = $this->listings->findById($listingId);

        if ($listing === null) {
            return false;
        }

        if ((int) $listing['owner_user_id'] === $userId) {
            return true;
        }

        if (
            $listing['status'] !== 'published'
            || !in_array($listing['visibility'], ['public', 'unlisted'], true)
            || $listing['published_at'] === null
        ) {
            return false;
        }

        return $this->access->hasValidGrant($userId, $listingId);
    }

    /** @return array<string, mixed> */
    public function grantAccess(int $userId, int $listingId, ?int $grantedByUserId, string $source = 'test', ?string $expiresAt = null): array
    {
        if (!in_array($source, ['manual', 'test'], true)) {
            throw new RuntimeException('Origen de acceso privado no permitido.');
        }

        if (!$this->isActiveUser($userId)) {
            throw new RuntimeException('Usuario no válido para acceso privado.');
        }

        if ($grantedByUserId !== null && !$this->isActiveUser($grantedByUserId)) {
            throw new RuntimeException('Otorgante no válido para acceso privado.');
        }

        if ($this->listings->findById($listingId) === null) {
            throw new RuntimeException('Listing no válido para acceso privado.');
        }

        return $this->access->createGrant($userId, $listingId, $grantedByUserId, $source, $expiresAt);
    }

    public function revokeAccess(int $userId, int $listingId): bool
    {
        return $this->access->revokeGrant($userId, $listingId);
    }

    private function isActiveUser(int $userId): bool
    {
        $context = $this->users->findAuthorizationContext($userId);

        return $context !== null
            && $context['status'] === 'active'
            && $context['deleted_at'] === null;
    }
}
