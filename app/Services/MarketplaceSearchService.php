<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use App\Repositories\ListingRepository;

final class MarketplaceSearchService
{
    public const PER_PAGE = 12;

    private const TYPES = ['physical_product', 'digital_content', 'service', 'bundle'];
    private const SORTS = ['newest', 'oldest', 'price_asc', 'price_desc'];

    public function __construct(
        private readonly ListingRepository $listings
    ) {
    }

    /**
     * @return array{
     *   filters: array<string, mixed>,
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   perPage: int,
     *   currentPage: int,
     *   lastPage: int,
     *   query: array<string, scalar>
     * }
     */
    public function search(Request $request): array
    {
        $filters = $this->normalize($request);

        if ($filters['impossible'] === true) {
            return $this->payload($filters, [], 0, 1, 1);
        }

        $total = $this->listings->countPublishedPublic($filters);
        $lastPage = $total === 0 ? 1 : (int) ceil($total / self::PER_PAGE);
        $page = $filters['page'];

        if ($total === 0) {
            $page = 1;
        } elseif ($page > $lastPage) {
            $page = $lastPage;
        }

        $filters['page'] = $page;
        $filters['per_page'] = self::PER_PAGE;
        $items = $total === 0 ? [] : $this->listings->searchPublishedPublic($filters);

        return $this->payload($filters, $items, $total, $page, $lastPage);
    }

    /**
     * @return array{
     *   q: string|null,
     *   category: string|null,
     *   type: string|null,
     *   min_price: string|null,
     *   max_price: string|null,
     *   creator: string|null,
     *   sort: string,
     *   page: int,
     *   per_page: int,
     *   impossible: bool
     * }
     */
    public function normalize(Request $request): array
    {
        $q = $this->stringParam($request->query('q'));
        if ($this->length($q) > 100) {
            $q = $this->clip($q, 100);
        }

        $category = strtolower($this->stringParam($request->query('category')));
        $type = $this->stringParam($request->query('type'));
        $creator = strtolower($this->stringParam($request->query('creator')));
        $sort = $this->stringParam($request->query('sort', 'newest'));
        $minPrice = $this->parsePrice($request->query('min_price'));
        $maxPrice = $this->parsePrice($request->query('max_price'));

        $impossible = $minPrice !== null && $maxPrice !== null && $this->priceToCents($minPrice) > $this->priceToCents($maxPrice);

        return [
            'q' => $q === '' ? null : $q,
            'category' => $category === '' ? null : $category,
            'type' => in_array($type, self::TYPES, true) ? $type : null,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'creator' => $creator === '' ? null : $creator,
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'newest',
            'page' => $this->parsePage($request->query('page')),
            'per_page' => self::PER_PAGE,
            'impossible' => $impossible,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, scalar>
     */
    public function queryParams(array $filters, int $page): array
    {
        $query = [];

        foreach (['q', 'category', 'type', 'min_price', 'max_price', 'creator'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== null && $filters[$key] !== '') {
                $query[$key] = (string) $filters[$key];
            }
        }

        if (isset($filters['sort']) && is_string($filters['sort']) && $filters['sort'] !== '') {
            $query['sort'] = $filters['sort'];
        }

        if ($page > 1) {
            $query['page'] = $page;
        }

        return $query;
    }

    private function stringParam(mixed $value, string $default = ''): string
    {
        return is_string($value) ? trim($value) : $default;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function clip(string $value, int $max): string
    {
        return function_exists('mb_substr') ? (string) mb_substr($value, 0, $max) : substr($value, 0, $max);
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

    private function parsePrice(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || preg_match('/\A(\d{1,10})(?:\.(\d{1,2}))?\z/', $value, $matches) !== 1) {
            return null;
        }

        $whole = ltrim($matches[1], '0');
        $fraction = str_pad($matches[2] ?? '00', 2, '0');

        return ($whole === '' ? '0' : $whole) . '.' . $fraction;
    }

    private function priceToCents(string $price): int
    {
        [$whole, $fraction] = explode('.', $price);

        return ((int) $whole * 100) + (int) $fraction;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<array<string, mixed>> $items
     * @return array{
     *   filters: array<string, mixed>,
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   perPage: int,
     *   currentPage: int,
     *   lastPage: int,
     *   query: array<string, scalar>
     * }
     */
    private function payload(array $filters, array $items, int $total, int $page, int $lastPage): array
    {
        return [
            'filters' => $filters,
            'items' => $items,
            'total' => $total,
            'perPage' => self::PER_PAGE,
            'currentPage' => $page,
            'lastPage' => $lastPage,
            'query' => $this->queryParams($filters, $page),
        ];
    }
}
