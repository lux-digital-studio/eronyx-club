<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class CategoryRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /** @return list<array{id: int, name: string, slug: string}> */
    public function findActive(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, name, slug
             FROM categories
             WHERE status = 'active'
             ORDER BY name ASC"
        );

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
     * @param list<int> $ids
     * @return list<array{id: int, name: string, slug: string}>
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id, name, slug
             FROM categories
             WHERE status = 'active' AND id IN ({$placeholders})
             ORDER BY name ASC"
        );
        $statement->execute($ids);

        return array_map(
            static fn (array $category): array => [
                'id' => (int) $category['id'],
                'name' => (string) $category['name'],
                'slug' => (string) $category['slug'],
            ],
            $statement->fetchAll()
        );
    }
}
