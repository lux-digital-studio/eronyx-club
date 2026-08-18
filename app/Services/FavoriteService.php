<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FavoriteRepository;
use App\Repositories\ListingRepository;
use RuntimeException;

final class FavoriteService
{
    public const PER_PAGE = 12;

    public function __construct(
        private readonly FavoriteRepository $favorites,
        private readonly ListingRepository $listings
    ) {
    }

    public function addFavorite(int $userId, int $listingId): void
    {
        $listing = $this->listings->findById($listingId);

        if ($listing === null) {
            throw new RuntimeException('not_found');
        }

        if ($this->isFavoritable($listing) && (int) $listing['owner_user_id'] === $userId) {
            throw new RuntimeException('forbidden');
        }

        if (!$this->isFavoritable($listing)) {
            throw new RuntimeException('not_found');
        }

        if (!$this->favorites->add($userId, $listingId)) {
            throw new RuntimeException('not_found');
        }
    }

    public function removeFavorite(int $userId, int $listingId): void
    {
        $this->favorites->remove($userId, $listingId);
    }

    public function isFavorite(int $userId, int $listingId): bool
    {
        return $this->favorites->exists($userId, $listingId);
    }

    /**
     * @param list<int> $listingIds
     * @return list<int>
     */
    public function listingIdsForUser(int $userId, array $listingIds): array
    {
        return $this->favorites->listingIdsForUser($userId, $listingIds);
    }

    /**
     * Unlisted listings are allowed in this list: the user saved them from a
     * direct URL. They never appear in the marketplace index.
     *
     * Favorites of listings that later become private/draft/rejected/soft-deleted
     * are hidden here; the favorites row is kept until hard delete (CASCADE).
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   perPage: int,
     *   currentPage: int,
     *   lastPage: int
     * }
     */
    public function listFavorites(int $userId, mixed $page): array
    {
        $page = $this->parsePage($page);
        $total = $this->favorites->countVisibleByUser($userId);
        $lastPage = $total === 0 ? 1 : (int) ceil($total / self::PER_PAGE);

        if ($total === 0) {
            $page = 1;
        } elseif ($page > $lastPage) {
            $page = $lastPage;
        }

        $offset = ($page - 1) * self::PER_PAGE;
        $items = $total === 0 ? [] : $this->favorites->findVisibleByUser($userId, self::PER_PAGE, $offset);

        return [
            'items' => $items,
            'total' => $total,
            'perPage' => self::PER_PAGE,
            'currentPage' => $page,
            'lastPage' => $lastPage,
        ];
    }

    /** @param array<string, mixed> $listing */
    private function isFavoritable(array $listing): bool
    {
        return ($listing['status'] ?? '') === 'published'
            && ($listing['published_at'] ?? null) !== null
            && ($listing['published_at'] ?? '') !== ''
            && in_array($listing['visibility'] ?? '', ['public', 'unlisted'], true);
    }

    private function parsePage(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,8}\z/', $value) === 1) {
            return (int) $value;
        }

        return 1;
    }
}
