<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ListingRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, owner_user_id, creator_profile_id, title, slug, description, listing_type, status,
                    price, currency, visibility, published_at, created_at, updated_at
             FROM listings
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $listing = $statement->fetch();

        return is_array($listing) ? $this->normalizeListing($listing) : null;
    }

    /** @return array<string, mixed>|null */
    public function findOwnedById(int $id, int $ownerUserId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, owner_user_id, creator_profile_id, title, slug, description, listing_type, status,
                    price, currency, visibility, published_at, created_at, updated_at
             FROM listings
             WHERE id = :id AND owner_user_id = :owner_user_id AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'id' => $id,
            'owner_user_id' => $ownerUserId,
        ]);
        $listing = $statement->fetch();

        return is_array($listing) ? $this->normalizeListing($listing) : null;
    }

    /** @return list<array<string, mixed>> */
    public function findAllByOwner(int $ownerUserId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, title, slug, listing_type, status, price, currency, visibility, created_at, updated_at
             FROM listings
             WHERE owner_user_id = :owner_user_id AND deleted_at IS NULL
             ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['owner_user_id' => $ownerUserId]);

        return array_map(fn (array $listing): array => $this->normalizeListing($listing), $statement->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function findPendingReview(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, owner_user_id, title, slug, listing_type, status, price, currency, visibility, created_at, updated_at
             FROM listings
             WHERE status = 'pending_review' AND deleted_at IS NULL
             ORDER BY updated_at ASC, id ASC"
        );

        return array_map(fn (array $listing): array => $this->normalizeListing($listing), $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findPendingReviewById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, owner_user_id, creator_profile_id, title, slug, description, listing_type, status,
                    price, currency, visibility, published_at, created_at, updated_at
             FROM listings
             WHERE id = :id AND status = 'pending_review' AND deleted_at IS NULL
             LIMIT 1"
        );
        $statement->execute(['id' => $id]);
        $listing = $statement->fetch();

        return is_array($listing) ? $this->normalizeListing($listing) : null;
    }

    /** @return list<array<string, mixed>> */
    public function findPublishedPublic(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, owner_user_id, title, slug, description, listing_type, price, currency, visibility, published_at
             FROM listings
             WHERE status = 'published'
                AND visibility = 'public'
                AND published_at IS NOT NULL
                AND deleted_at IS NULL
             ORDER BY published_at DESC, id DESC"
        );

        return array_map(fn (array $listing): array => $this->normalizeListing($listing), $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findPublishedPublicBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, owner_user_id, title, slug, description, listing_type, price, currency, visibility, published_at
             FROM listings
             WHERE slug = :slug
                AND status = 'published'
                AND visibility = 'public'
                AND published_at IS NOT NULL
                AND deleted_at IS NULL
             LIMIT 1"
        );
        $statement->execute(['slug' => $slug]);
        $listing = $statement->fetch();

        return is_array($listing) ? $this->normalizeListing($listing) : null;
    }

    /** @return array<string, mixed>|null */
    public function findPublishedVisibleBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, owner_user_id, title, slug, description, listing_type, price, currency, visibility, published_at
             FROM listings
             WHERE slug = :slug
                AND status = 'published'
                AND visibility IN ('public', 'unlisted')
                AND published_at IS NOT NULL
                AND deleted_at IS NULL
             LIMIT 1"
        );
        $statement->execute(['slug' => $slug]);
        $listing = $statement->fetch();

        return is_array($listing) ? $this->normalizeListing($listing) : null;
    }

    /** @return list<array<string, mixed>> */
    public function findPublishedPublicByOwner(int $ownerUserId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, owner_user_id, title, slug, description, listing_type, price, currency, visibility, published_at
             FROM listings
             WHERE owner_user_id = :owner_user_id
                AND status = 'published'
                AND visibility = 'public'
                AND published_at IS NOT NULL
                AND deleted_at IS NULL
             ORDER BY published_at DESC, id DESC"
        );
        $statement->execute(['owner_user_id' => $ownerUserId]);

        return array_map(fn (array $listing): array => $this->normalizeListing($listing), $statement->fetchAll());
    }

    public function slugExists(string $slug, ?int $ignoreListingId = null): bool
    {
        $sql = 'SELECT 1 FROM listings WHERE slug = :slug';
        $params = ['slug' => $slug];

        if ($ignoreListingId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreListingId;
        }

        $sql .= ' LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /** @param array{owner_user_id: int, title: string, slug: string, description: string|null, listing_type: string, price: string, currency: string, visibility: string} $data */
    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO listings (
                owner_user_id, creator_profile_id, title, slug, description, listing_type, status,
                price, currency, visibility
             ) VALUES (
                :owner_user_id, NULL, :title, :slug, :description, :listing_type, 'draft',
                :price, :currency, :visibility
             )"
        );
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{title: string, slug: string, description: string|null, listing_type: string, price: string, currency: string, visibility: string} $data */
    public function updateEditableDraft(int $id, int $ownerUserId, array $data): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE listings
             SET title = :title,
                 slug = :slug,
                 description = :description,
                 listing_type = :listing_type,
                 price = :price,
                 currency = :currency,
                 visibility = :visibility,
                 status = 'draft',
                 published_at = NULL
             WHERE id = :id
                AND owner_user_id = :owner_user_id
                AND status IN ('draft', 'rejected')
                AND deleted_at IS NULL"
        );
        $statement->execute([
            'id' => $id,
            'owner_user_id' => $ownerUserId,
            ...$data,
        ]);

        return $statement->rowCount() === 1;
    }

    public function markPendingReview(int $id, int $ownerUserId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE listings
             SET status = 'pending_review'
             WHERE id = :id
                AND owner_user_id = :owner_user_id
                AND status = 'draft'
                AND deleted_at IS NULL"
        );
        $statement->execute([
            'id' => $id,
            'owner_user_id' => $ownerUserId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function approvePending(int $id): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE listings
             SET status = 'published',
                 published_at = CURRENT_TIMESTAMP
             WHERE id = :id
                AND status = 'pending_review'
                AND deleted_at IS NULL"
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function rejectPending(int $id): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE listings
             SET status = 'rejected'
             WHERE id = :id
                AND status = 'pending_review'
                AND deleted_at IS NULL"
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function suspendEligible(int $id, string $expectedStatus): bool
    {
        if (!in_array($expectedStatus, ['published', 'pending_review'], true)) {
            return false;
        }

        $statement = $this->pdo->prepare(
            "UPDATE listings
             SET status = 'suspended'
             WHERE id = :id
                AND status = :expected_status
                AND deleted_at IS NULL"
        );
        $statement->execute([
            'id' => $id,
            'expected_status' => $expectedStatus,
        ]);

        return $statement->rowCount() === 1;
    }

    public function restoreSuspended(int $id, string $previousStatus): bool
    {
        if (!in_array($previousStatus, ['published', 'pending_review'], true)) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE listings
             SET status = :previous_status
             WHERE id = :id
                AND status = :suspended_status
                AND deleted_at IS NULL'
        );
        $statement->execute([
            'previous_status' => $previousStatus,
            'id' => $id,
            'suspended_status' => 'suspended',
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function findSummariesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach ($ids as $index => $id) {
            $name = 'listing_id_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $id;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, owner_user_id, title, slug, status, visibility, published_at, deleted_at
             FROM listings
             WHERE id IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($params);

        $listings = [];

        foreach ($statement->fetchAll() as $row) {
            $normalized = $this->normalizeListing($row);
            $listings[$normalized['id']] = $normalized;
        }

        return $listings;
    }

    /** @param list<int> $categoryIds */
    public function replaceCategories(int $listingId, array $categoryIds): void
    {
        $delete = $this->pdo->prepare('DELETE FROM listing_categories WHERE listing_id = :listing_id');
        $delete->execute(['listing_id' => $listingId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO listing_categories (listing_id, category_id) VALUES (:listing_id, :category_id)'
        );

        foreach (array_values(array_unique($categoryIds)) as $categoryId) {
            $insert->execute([
                'listing_id' => $listingId,
                'category_id' => $categoryId,
            ]);
        }
    }

    /** @return list<array{id: int, name: string, slug: string}> */
    public function findCategoriesForListing(int $listingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.name, c.slug
             FROM listing_categories lc
             INNER JOIN categories c ON c.id = lc.category_id
             WHERE lc.listing_id = :listing_id
             ORDER BY c.name ASC'
        );
        $statement->execute(['listing_id' => $listingId]);

        return array_map(
            static fn (array $category): array => [
                'id' => (int) $category['id'],
                'name' => (string) $category['name'],
                'slug' => (string) $category['slug'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @param list<int> $listingIds
     * @return array<int, list<array{id: int, name: string, slug: string}>>
     */
    public function findCategoriesForListings(array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_filter($listingIds, static fn (int $id): bool => $id > 0)));

        if ($listingIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($listingIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT lc.listing_id, c.id, c.name, c.slug
             FROM listing_categories lc
             INNER JOIN categories c ON c.id = lc.category_id
             WHERE lc.listing_id IN ({$placeholders})
             ORDER BY c.name ASC"
        );
        $statement->execute($listingIds);

        $categories = [];

        foreach ($statement->fetchAll() as $category) {
            $listingId = (int) $category['listing_id'];
            $categories[$listingId] ??= [];
            $categories[$listingId][] = [
                'id' => (int) $category['id'],
                'name' => (string) $category['name'],
                'slug' => (string) $category['slug'],
            ];
        }

        return $categories;
    }

    /** @return list<int> */
    public function findCategoryIdsForListing(int $listingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT category_id FROM listing_categories WHERE listing_id = :listing_id ORDER BY category_id ASC'
        );
        $statement->execute(['listing_id' => $listingId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Search published public listings. Filters use EXISTS/subqueries so
     * listing_categories, roles and media joins cannot duplicate rows.
     *
     * @param array{
     *   q?: string|null,
     *   category?: string|null,
     *   type?: string|null,
     *   min_price?: string|null,
     *   max_price?: string|null,
     *   creator?: string|null,
     *   sort?: string,
     *   page?: int,
     *   per_page?: int
     * } $filters
     * @return list<array<string, mixed>>
     */
    public function searchPublishedPublic(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, (int) ($filters['per_page'] ?? 12));
        $offset = ($page - 1) * $perPage;
        $built = $this->publishedPublicFilterSql($filters);

        $sql = $this->publishedPublicSelectSql()
            . $built['sql']
            . $this->publishedPublicOrderSql((string) ($filters['sort'] ?? 'newest'))
            . ' LIMIT :limit OFFSET :offset';

        $statement = $this->pdo->prepare($sql);

        foreach ($built['params'] as $name => $value) {
            $statement->bindValue(':' . ltrim((string) $name, ':'), $value);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $listing): array => $this->normalizeMarketplaceCard($listing), $statement->fetchAll());
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countPublishedPublic(array $filters): int
    {
        $built = $this->publishedPublicFilterSql($filters);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM listings l' . $built['sql']
        );
        $statement->execute($built['params']);

        return (int) $statement->fetchColumn();
    }

    /**
     * Shared WHERE for search + count. Named placeholders are unique
     * (PDO native prepares cannot reuse the same name twice).
     *
     * @param array<string, mixed> $filters
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function publishedPublicFilterSql(array $filters): array
    {
        $sql = " WHERE l.status = 'published'
                AND l.visibility = 'public'
                AND l.published_at IS NOT NULL
                AND l.deleted_at IS NULL";
        $params = [];

        $q = isset($filters['q']) && is_string($filters['q']) ? $filters['q'] : null;
        if ($q !== null && $q !== '') {
            $like = $this->likeContains($q);
            $sql .= ' AND (l.title LIKE :q_title ESCAPE \'\\\\\' OR l.description LIKE :q_description ESCAPE \'\\\\\')';
            $params['q_title'] = $like;
            $params['q_description'] = $like;
        }

        $category = isset($filters['category']) && is_string($filters['category']) ? $filters['category'] : null;
        if ($category !== null && $category !== '') {
            $sql .= " AND EXISTS (
                SELECT 1
                FROM listing_categories lc
                INNER JOIN categories c ON c.id = lc.category_id
                WHERE lc.listing_id = l.id
                    AND c.slug = :category_slug
                    AND c.status = 'active'
             )";
            $params['category_slug'] = $category;
        }

        $type = isset($filters['type']) && is_string($filters['type']) ? $filters['type'] : null;
        if ($type !== null && $type !== '') {
            $sql .= ' AND l.listing_type = :listing_type';
            $params['listing_type'] = $type;
        }

        $minPrice = isset($filters['min_price']) && is_string($filters['min_price']) ? $filters['min_price'] : null;
        if ($minPrice !== null) {
            $sql .= ' AND l.price >= :min_price';
            $params['min_price'] = $minPrice;
        }

        $maxPrice = isset($filters['max_price']) && is_string($filters['max_price']) ? $filters['max_price'] : null;
        if ($maxPrice !== null) {
            $sql .= ' AND l.price <= :max_price';
            $params['max_price'] = $maxPrice;
        }

        $creator = isset($filters['creator']) && is_string($filters['creator']) ? $filters['creator'] : null;
        if ($creator !== null && $creator !== '') {
            $sql .= " AND EXISTS (
                SELECT 1
                FROM profiles p_filter
                INNER JOIN users u_filter ON u_filter.id = p_filter.user_id
                INNER JOIN creator_profiles cp_filter ON cp_filter.user_id = u_filter.id
                INNER JOIN user_roles ur_filter ON ur_filter.user_id = u_filter.id
                INNER JOIN roles r_filter ON r_filter.id = ur_filter.role_id
                WHERE p_filter.user_id = l.owner_user_id
                    AND p_filter.username = :creator_username
                    AND p_filter.deleted_at IS NULL
                    AND u_filter.status = 'active'
                    AND u_filter.deleted_at IS NULL
                    AND cp_filter.status = 'active'
                    AND cp_filter.deleted_at IS NULL
                    AND r_filter.name = 'creator'
             )";
            $params['creator_username'] = $creator;
        }

        return ['sql' => $sql, 'params' => $params];
    }

    private function publishedPublicSelectSql(): string
    {
        return "SELECT l.id, l.owner_user_id, l.title, l.slug, l.price, l.currency, l.listing_type, l.published_at,
                    creator.display_name AS creator_display_name,
                    creator.username AS creator_username,
                    creator.avatar_media_id AS creator_avatar_media_id,
                    cover.cover_media_id
             FROM listings l
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

    private function publishedPublicOrderSql(string $sort): string
    {
        return match ($sort) {
            'oldest' => ' ORDER BY l.published_at ASC, l.id ASC',
            'price_asc' => ' ORDER BY l.price ASC, l.id ASC',
            'price_desc' => ' ORDER BY l.price DESC, l.id DESC',
            default => ' ORDER BY l.published_at DESC, l.id DESC',
        };
    }

    private function likeContains(string $value): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        return '%' . $escaped . '%';
    }

    /** @param array<string, mixed> $listing @return array<string, mixed> */
    private function normalizeMarketplaceCard(array $listing): array
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

    /** @param array<string, mixed> $listing @return array<string, mixed> */
    private function normalizeListing(array $listing): array
    {
        foreach (['id', 'owner_user_id', 'creator_profile_id'] as $key) {
            if (array_key_exists($key, $listing) && $listing[$key] !== null) {
                $listing[$key] = (int) $listing[$key];
            }
        }

        if (array_key_exists('price', $listing)) {
            $listing['price'] = number_format((float) $listing['price'], 2, '.', '');
        }

        return $listing;
    }
}
