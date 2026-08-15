<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\ListingRepository;
use App\Repositories\MediaRepository;
use RuntimeException;
use Throwable;

final class ListingMediaService
{
    private const ALLOWED_USAGE_TYPES = ['cover', 'gallery', 'preview'];
    private const EDITABLE_STATUSES = ['draft', 'rejected'];
    private const MAX_IMAGES_PER_LISTING = 10;

    private \PDO $pdo;
    private ListingRepository $listings;
    private MediaRepository $media;
    private MediaStorageService $storage;

    public function __construct(
        private readonly Auth $auth,
        ?\PDO $pdo = null,
        ?ListingRepository $listings = null,
        ?MediaRepository $media = null,
        ?MediaStorageService $storage = null
    ) {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->listings = $listings ?? new ListingRepository($this->pdo);
        $this->media = $media ?? new MediaRepository($this->pdo);
        $this->storage = $storage ?? new MediaStorageService();
    }

    /** @param array<string, mixed>|null $file */
    public function upload(int $listingId, ?array $file, string $usageType): int
    {
        $ownerUserId = $this->ownerUserId();
        $listing = $this->editableOwnedListing($listingId, $ownerUserId);

        if (!in_array($usageType, self::ALLOWED_USAGE_TYPES, true)) {
            throw new RuntimeException('Tipo de imagen no permitido.');
        }

        if ($this->media->countActiveForListing($listingId) >= self::MAX_IMAGES_PER_LISTING) {
            throw new RuntimeException('Este listing ya tiene el máximo de 10 imágenes.');
        }

        $prepared = $this->storage->prepareUpload($file);

        if ($this->media->checksumExistsForListing($listingId, $prepared['checksum'])) {
            throw new RuntimeException('Esta imagen ya está asociada al listing.');
        }

        $moved = false;

        try {
            $this->storage->movePreparedUpload($prepared);
            $moved = true;

            $this->pdo->beginTransaction();

            $mediaId = $this->media->createMedia([
                'owner_user_id' => $ownerUserId,
                'storage_key' => $prepared['storage_key'],
                'mime_type' => $prepared['mime_type'],
                'size_bytes' => $prepared['size_bytes'],
                'checksum' => $prepared['checksum'],
            ]);

            if ($usageType === 'cover') {
                $this->media->demoteCoversToGallery($listingId);
            }

            $this->media->attachToListing(
                $listing['id'],
                $mediaId,
                $usageType,
                $this->media->nextSortOrder($listingId, $usageType)
            );

            $this->pdo->commit();

            return $mediaId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($moved) {
                $this->storage->deleteByStorageKey($prepared['storage_key']);
            }

            throw $exception;
        }
    }

    public function setCover(int $listingId, int $mediaId): bool
    {
        $ownerUserId = $this->ownerUserId();
        $this->editableOwnedListing($listingId, $ownerUserId);
        $media = $this->activeOwnedListingMedia($listingId, $mediaId, $ownerUserId);

        if (!in_array($media['usage_type'], self::ALLOWED_USAGE_TYPES, true)) {
            return false;
        }

        try {
            $this->pdo->beginTransaction();
            $this->media->demoteCoversToGallery($listingId);
            $updated = $this->media->updateUsageType($listingId, $mediaId, 'cover');
            $this->pdo->commit();

            return $updated;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function delete(int $listingId, int $mediaId): bool
    {
        $ownerUserId = $this->ownerUserId();
        $this->editableOwnedListing($listingId, $ownerUserId);
        $media = $this->activeOwnedListingMedia($listingId, $mediaId, $ownerUserId);

        try {
            $this->pdo->beginTransaction();
            $deleted = $this->media->deleteAssociation($listingId, $mediaId);

            if (!$deleted) {
                $this->pdo->rollBack();

                return false;
            }

            $referenceCount = $this->media->countReferences($mediaId);
            $shouldDeleteFile = $referenceCount === 0;

            if ($shouldDeleteFile) {
                $this->media->softDeleteMedia($mediaId);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        if ($shouldDeleteFile) {
            $this->storage->deleteByStorageKey((string) $media['storage_key']);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function ownedListing(int $listingId): array
    {
        $listing = $this->listings->findById($listingId);

        if ($listing === null) {
            throw new RuntimeException('not_found');
        }

        $ownerUserId = $this->ownerUserId();
        $owned = $this->listings->findOwnedById($listingId, $ownerUserId);

        if ($owned === null) {
            throw new RuntimeException('forbidden');
        }

        return $owned;
    }

    /** @return list<array<string, mixed>> */
    public function mediaForListing(int $listingId): array
    {
        return $this->media->findByListing($listingId);
    }

    public function canModify(array $listing): bool
    {
        return in_array($listing['status'] ?? '', self::EDITABLE_STATUSES, true);
    }

    /** @return array<string, mixed> */
    private function editableOwnedListing(int $listingId, int $ownerUserId): array
    {
        $listing = $this->listings->findById($listingId);

        if ($listing === null) {
            throw new RuntimeException('not_found');
        }

        $owned = $this->listings->findOwnedById($listingId, $ownerUserId);

        if ($owned === null) {
            throw new RuntimeException('forbidden');
        }

        if (!$this->canModify($owned)) {
            throw new RuntimeException('forbidden');
        }

        return $owned;
    }

    /** @return array<string, mixed> */
    private function activeOwnedListingMedia(int $listingId, int $mediaId, int $ownerUserId): array
    {
        $media = $this->media->findOwnedListingMedia($listingId, $mediaId, $ownerUserId);

        if ($media === null) {
            throw new RuntimeException('forbidden');
        }

        if (
            $media['status'] !== 'active'
            || $media['media_type'] !== 'image'
            || $media['deleted_at'] !== null
        ) {
            throw new RuntimeException('forbidden');
        }

        return $media;
    }

    private function ownerUserId(): int
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            throw new RuntimeException('forbidden');
        }

        return $userId;
    }
}
