<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class MediaRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /** @param array{owner_user_id: int, storage_key: string, media_type: string, visibility: string, mime_type: string, size_bytes: int, checksum: string} $data */
    public function createMedia(array $data): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO media_files (
                owner_user_id, storage_disk, storage_key, media_type, visibility,
                mime_type, size_bytes, checksum, status
             ) VALUES (
                :owner_user_id, 'local', :storage_key, :media_type, :visibility,
                :mime_type, :size_bytes, :checksum, 'active'
             )"
        );
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function attachToListing(int $listingId, int $mediaFileId, string $usageType, int $sortOrder): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO listing_media (listing_id, media_file_id, usage_type, sort_order)
             VALUES (:listing_id, :media_file_id, :usage_type, :sort_order)'
        );
        $statement->execute([
            'listing_id' => $listingId,
            'media_file_id' => $mediaFileId,
            'usage_type' => $usageType,
            'sort_order' => $sortOrder,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function findByListing(int $listingId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT lm.id AS relation_id, lm.listing_id, lm.media_file_id, lm.usage_type, lm.sort_order, lm.created_at,
                    mf.mime_type, mf.size_bytes, mf.status, mf.media_type, mf.visibility
             FROM listing_media lm
             INNER JOIN media_files mf ON mf.id = lm.media_file_id
             WHERE lm.listing_id = :listing_id
                AND mf.deleted_at IS NULL
             ORDER BY FIELD(lm.usage_type, 'cover', 'gallery', 'preview', 'private_content'), lm.sort_order ASC, lm.id ASC"
        );
        $statement->execute(['listing_id' => $listingId]);

        return array_map(fn (array $media): array => $this->normalize($media), $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findOwnedListingMedia(int $listingId, int $mediaFileId, int $ownerUserId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT lm.id AS relation_id, lm.listing_id, lm.media_file_id, lm.usage_type, lm.sort_order,
                    mf.owner_user_id, mf.storage_key, mf.mime_type, mf.size_bytes, mf.status, mf.media_type, mf.visibility, mf.deleted_at
             FROM listing_media lm
             INNER JOIN media_files mf ON mf.id = lm.media_file_id
             INNER JOIN listings l ON l.id = lm.listing_id
             WHERE lm.listing_id = :listing_id
                AND lm.media_file_id = :media_file_id
                AND l.owner_user_id = :listing_owner_user_id
                AND mf.owner_user_id = :media_owner_user_id
             LIMIT 1"
        );
        $statement->execute([
            'listing_id' => $listingId,
            'media_file_id' => $mediaFileId,
            'listing_owner_user_id' => $ownerUserId,
            'media_owner_user_id' => $ownerUserId,
        ]);
        $media = $statement->fetch();

        return is_array($media) ? $this->normalize($media) : null;
    }

    public function countActivePublicImagesForListing(int $listingId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM listing_media lm
             INNER JOIN media_files mf ON mf.id = lm.media_file_id
             WHERE lm.listing_id = :listing_id
                AND mf.media_type = 'image'
                AND mf.visibility = 'public'
                AND lm.usage_type IN ('cover', 'gallery', 'preview')
                AND mf.status = 'active'
                AND mf.deleted_at IS NULL"
        );
        $statement->execute(['listing_id' => $listingId]);

        return (int) $statement->fetchColumn();
    }

    public function countActivePrivateContentForListing(int $listingId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM listing_media lm
             INNER JOIN media_files mf ON mf.id = lm.media_file_id
             WHERE lm.listing_id = :listing_id
                AND lm.usage_type = 'private_content'
                AND mf.visibility = 'private'
                AND mf.status = 'active'
                AND mf.deleted_at IS NULL"
        );
        $statement->execute(['listing_id' => $listingId]);

        return (int) $statement->fetchColumn();
    }

    public function checksumExistsForListing(int $listingId, string $checksum): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM listing_media lm
             INNER JOIN media_files mf ON mf.id = lm.media_file_id
             WHERE lm.listing_id = :listing_id
                AND mf.checksum = :checksum
                AND mf.status = 'active'
                AND mf.deleted_at IS NULL
             LIMIT 1"
        );
        $statement->execute([
            'listing_id' => $listingId,
            'checksum' => $checksum,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function hasValidCoverForListing(int $listingId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM listing_media lm
             INNER JOIN media_files mf ON mf.id = lm.media_file_id
             WHERE lm.listing_id = :listing_id
                AND lm.usage_type = 'cover'
                AND mf.media_type = 'image'
                AND mf.visibility = 'public'
                AND mf.status = 'active'
                AND mf.deleted_at IS NULL
             LIMIT 1"
        );
        $statement->execute(['listing_id' => $listingId]);

        return $statement->fetchColumn() !== false;
    }

    public function nextSortOrder(int $listingId, string $usageType): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1
             FROM listing_media
             WHERE listing_id = :listing_id AND usage_type = :usage_type'
        );
        $statement->execute([
            'listing_id' => $listingId,
            'usage_type' => $usageType,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function demoteCoversToGallery(int $listingId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE listing_media
             SET usage_type = 'gallery'
             WHERE listing_id = :listing_id AND usage_type = 'cover'"
        );
        $statement->execute(['listing_id' => $listingId]);
    }

    public function updateUsageType(int $listingId, int $mediaFileId, string $usageType): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE listing_media
             SET usage_type = :usage_type
             WHERE listing_id = :listing_id AND media_file_id = :media_file_id'
        );
        $statement->execute([
            'listing_id' => $listingId,
            'media_file_id' => $mediaFileId,
            'usage_type' => $usageType,
        ]);

        return true;
    }

    public function deleteAssociation(int $listingId, int $mediaFileId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM listing_media WHERE listing_id = :listing_id AND media_file_id = :media_file_id'
        );
        $statement->execute([
            'listing_id' => $listingId,
            'media_file_id' => $mediaFileId,
        ]);

        return true;
    }

    public function countReferences(int $mediaFileId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT (
                (SELECT COUNT(*) FROM listing_media WHERE media_file_id = :listing_media_file_id)
                +
                (SELECT COUNT(*) FROM profiles WHERE avatar_media_id = :avatar_media_file_id AND deleted_at IS NULL)
             )'
        );
        $statement->execute([
            'listing_media_file_id' => $mediaFileId,
            'avatar_media_file_id' => $mediaFileId,
        ]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $mediaFileId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, owner_user_id, storage_key, mime_type, size_bytes, status, media_type, visibility, deleted_at
             FROM media_files
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $mediaFileId]);
        $media = $statement->fetch();

        return is_array($media) ? $this->normalize($media) : null;
    }

    public function softDeleteMedia(int $mediaFileId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE media_files
             SET status = 'removed', deleted_at = CURRENT_TIMESTAMP
             WHERE id = :id AND deleted_at IS NULL"
        );
        $statement->execute(['id' => $mediaFileId]);

        return $statement->rowCount() === 1;
    }

    /** @return list<array<string, mixed>> */
    public function findDeliveryCandidates(int $mediaFileId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT mf.id, mf.owner_user_id, mf.storage_key, mf.mime_type, mf.size_bytes, mf.status,
                    mf.media_type, mf.visibility, mf.deleted_at, mf.checksum,
                    lm.listing_id, lm.usage_type,
                    l.owner_user_id AS listing_owner_user_id, l.status AS listing_status,
                    l.visibility AS listing_visibility, l.published_at, l.deleted_at AS listing_deleted_at,
                    p.id AS avatar_profile_id, p.deleted_at AS avatar_profile_deleted_at
             FROM media_files mf
             LEFT JOIN listing_media lm ON lm.media_file_id = mf.id
             LEFT JOIN listings l ON l.id = lm.listing_id
             LEFT JOIN profiles p ON p.avatar_media_id = mf.id
             WHERE mf.id = :id"
        );
        $statement->execute(['id' => $mediaFileId]);

        return array_map(fn (array $media): array => $this->normalize($media), $statement->fetchAll());
    }

    /**
     * @param list<int> $listingIds
     * @return array<int, int>
     */
    public function findCoverIdsForListings(array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_filter($listingIds, static fn (int $id): bool => $id > 0)));

        if ($listingIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($listingIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT lm.listing_id, MIN(lm.media_file_id) AS media_file_id
             FROM listing_media lm
             INNER JOIN media_files mf ON mf.id = lm.media_file_id
             WHERE lm.listing_id IN ({$placeholders})
                AND lm.usage_type = 'cover'
                AND mf.media_type = 'image'
                AND mf.visibility = 'public'
                AND mf.status = 'active'
                AND mf.deleted_at IS NULL
             GROUP BY lm.listing_id"
        );
        $statement->execute($listingIds);

        $covers = [];
        foreach ($statement->fetchAll() as $row) {
            $covers[(int) $row['listing_id']] = (int) $row['media_file_id'];
        }

        return $covers;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function findPublicMediaForListing(int $listingId): array
    {
        $media = $this->findByListing($listingId);
        $grouped = ['cover' => [], 'gallery' => [], 'preview' => []];

        foreach ($media as $item) {
            if (
                in_array($item['usage_type'], ['cover', 'gallery', 'preview'], true)
                && $item['status'] === 'active'
                && $item['media_type'] === 'image'
                && $item['visibility'] === 'public'
            ) {
                $grouped[$item['usage_type']][] = $item;
            }
        }

        return $grouped;
    }

    /** @return list<array<string, mixed>> */
    public function findPrivateMediaForListing(int $listingId): array
    {
        $media = $this->findByListing($listingId);
        $private = [];

        foreach ($media as $item) {
            if (
                $item['usage_type'] === 'private_content'
                && $item['visibility'] === 'private'
                && $item['status'] === 'active'
                && in_array($item['media_type'], ['image', 'video'], true)
            ) {
                $private[] = $item;
            }
        }

        return $private;
    }

    /** @param array<string, mixed> $media @return array<string, mixed> */
    private function normalize(array $media): array
    {
        foreach (['id', 'owner_user_id', 'listing_id', 'media_file_id', 'relation_id', 'sort_order', 'size_bytes', 'listing_owner_user_id', 'avatar_profile_id'] as $key) {
            if (array_key_exists($key, $media) && $media[$key] !== null) {
                $media[$key] = (int) $media[$key];
            }
        }

        return $media;
    }
}
