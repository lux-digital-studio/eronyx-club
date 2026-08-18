<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

final class FavoriteRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function exists(int $userId, int $listingId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM favorites
             WHERE user_id = :user_id AND listing_id = :listing_id
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'listing_id' => $listingId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function add(int $userId, int $listingId): bool
    {
        if ($this->exists($userId, $listingId)) {
            return true;
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO favorites (user_id, listing_id) VALUES (:user_id, :listing_id)'
            );
            $statement->execute([
                'user_id' => $userId,
                'listing_id' => $listingId,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicateKey($exception)) {
                return true;
            }

            if ($this->isForeignKeyFailure($exception)) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    public function remove(int $userId, int $listingId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM favorites
             WHERE user_id = :user_id AND listing_id = :listing_id'
        );
        $statement->execute([
            'user_id' => $userId,
            'listing_id' => $listingId,
        ]);

        return true;
    }

    /**
     * Visible favorites: published + public/unlisted, with card cover/creator data.
     * Unlisted items are included because the user saved them via a direct URL.
     *
     * @return list<array<string, mixed>>
     */
    public function findVisibleByUser(int $userId, int $limit, int $offset): array
    {
        $sql = $this->visibleSelectSql()
            . ' WHERE f.user_id = :user_id
                AND l.status = \'published\'
                AND l.published_at IS NOT NULL
                AND l.deleted_at IS NULL
                AND l.visibility IN (\'public\', \'unlisted\')
             ORDER BY f.created_at DESC, l.id DESC
             LIMIT :limit OFFSET :offset';

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $listing): array => $this->normalizeCard($listing), $statement->fetchAll());
    }

    public function countVisibleByUser(int $userId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM favorites f
             INNER JOIN listings l ON l.id = f.listing_id
             WHERE f.user_id = :user_id
                AND l.status = 'published'
                AND l.published_at IS NOT NULL
                AND l.deleted_at IS NULL
                AND l.visibility IN ('public', 'unlisted')"
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Batch favorite state for marketplace cards. One query, unique named placeholders.
     *
     * @param list<int> $listingIds
     * @return list<int>
     */
    public function listingIdsForUser(int $userId, array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_filter(
            $listingIds,
            static fn (int $id): bool => $id > 0
        )));

        if ($listingIds === []) {
            return [];
        }

        $placeholders = [];
        $params = ['user_id' => $userId];

        foreach ($listingIds as $index => $listingId) {
            $name = 'listing_id_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $listingId;
        }

        $statement = $this->pdo->prepare(
            'SELECT listing_id
             FROM favorites
             WHERE user_id = :user_id
                AND listing_id IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($params);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function visibleSelectSql(): string
    {
        return "SELECT l.id, l.owner_user_id, l.title, l.slug, l.price, l.currency, l.listing_type, l.published_at,
                    f.created_at AS favorited_at,
                    creator.display_name AS creator_display_name,
                    creator.username AS creator_username,
                    creator.avatar_media_id AS creator_avatar_media_id,
                    cover.cover_media_id
             FROM favorites f
             INNER JOIN listings l ON l.id = f.listing_id
             LEFT JOIN (
                SELECT p.user_id, p.display_name, p.username, p.avatar_media_id
                FROM profiles p
                INNER JOIN users u ON u.id = p.user_id
                INNER JOIN creator_profiles cp ON cp.user_id = p.user_id
                WHERE p.deleted_at IS NULL
                    AND u.status = 'active'
                    AND u.deleted_at IS NULL
                    AND cp.status = 'active'
                    AND cp.deleted_at IS NULL
                    AND EXISTS (
                        SELECT 1
                        FROM user_roles ur
                        INNER JOIN roles r ON r.id = ur.role_id
                        WHERE ur.user_id = p.user_id
                            AND r.name = 'creator'
                    )
             ) creator ON creator.user_id = l.owner_user_id
             LEFT JOIN (
                SELECT lm.listing_id, MIN(lm.media_file_id) AS cover_media_id
                FROM listing_media lm
                INNER JOIN media_files mf ON mf.id = lm.media_file_id
                WHERE lm.usage_type = 'cover'
                    AND mf.media_type = 'image'
                    AND mf.visibility = 'public'
                    AND mf.status = 'active'
                    AND mf.deleted_at IS NULL
                GROUP BY lm.listing_id
             ) cover ON cover.listing_id = l.id";
    }

    /** @param array<string, mixed> $listing @return array<string, mixed> */
    private function normalizeCard(array $listing): array
    {
        $listing['id'] = (int) $listing['id'];
        $listing['owner_user_id'] = (int) $listing['owner_user_id'];
        $listing['price'] = number_format((float) $listing['price'], 2, '.', '');
        $listing['cover_media_id'] = $listing['cover_media_id'] !== null ? (int) $listing['cover_media_id'] : null;
        $listing['creator_avatar_media_id'] = $listing['creator_avatar_media_id'] !== null
            ? (int) $listing['creator_avatar_media_id']
            : null;

        if ($listing['creator_username'] === null) {
            $listing['creator_display_name'] = null;
            $listing['creator_avatar_media_id'] = null;
        }

        return $listing;
    }

    private function isDuplicateKey(PDOException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    private function isForeignKeyFailure(PDOException $exception): bool
    {
        $code = (int) ($exception->errorInfo[1] ?? 0);

        return $code === 1451 || $code === 1452;
    }
}
