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
                    price, currency, visibility, created_at, updated_at
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
                    price, currency, visibility, created_at, updated_at
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
    public function updateDraft(int $id, int $ownerUserId, array $data): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE listings
             SET title = :title,
                 slug = :slug,
                 description = :description,
                 listing_type = :listing_type,
                 price = :price,
                 currency = :currency,
                 visibility = :visibility
             WHERE id = :id
                AND owner_user_id = :owner_user_id
                AND status = 'draft'
                AND deleted_at IS NULL"
        );
        $statement->execute([
            'id' => $id,
            'owner_user_id' => $ownerUserId,
            ...$data,
        ]);

        return $statement->rowCount() === 1;
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

    /** @return list<int> */
    public function findCategoryIdsForListing(int $listingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT category_id FROM listing_categories WHERE listing_id = :listing_id ORDER BY category_id ASC'
        );
        $statement->execute(['listing_id' => $listingId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
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
