<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SeoRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /** @return list<array{path: string, lastmod: string|null}> */
    public function publicListingUrls(): array
    {
        $statement = $this->pdo->query(
            "SELECT slug, DATE(updated_at) AS lastmod
             FROM listings
             WHERE status = 'published'
                AND visibility = 'public'
                AND published_at IS NOT NULL
                AND deleted_at IS NULL
             ORDER BY updated_at DESC, id DESC"
        );
        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $slug = (string) ($row['slug'] ?? '');

            if ($slug === '') {
                continue;
            }

            $rows[] = [
                'path' => '/marketplace/' . $slug,
                'lastmod' => is_string($row['lastmod'] ?? null) && $row['lastmod'] !== '' ? $row['lastmod'] : null,
            ];
        }

        return $rows;
    }

    /** @return list<array{path: string, lastmod: string|null}> */
    public function publicCreatorUrls(): array
    {
        $statement = $this->pdo->query(
            "SELECT DISTINCT p.username, DATE(p.updated_at) AS lastmod
             FROM profiles p
             INNER JOIN users u ON u.id = p.user_id
             INNER JOIN creator_profiles cp ON cp.user_id = u.id
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE p.deleted_at IS NULL
                AND u.status = 'active'
                AND u.deleted_at IS NULL
                AND cp.status = 'active'
                AND cp.deleted_at IS NULL
                AND r.name = 'creator'
             ORDER BY p.username ASC"
        );
        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $username = (string) ($row['username'] ?? '');

            if ($username === '') {
                continue;
            }

            $rows[] = [
                'path' => '/creator/' . $username,
                'lastmod' => is_string($row['lastmod'] ?? null) && $row['lastmod'] !== '' ? $row['lastmod'] : null,
            ];
        }

        return $rows;
    }
}
