<?php

declare(strict_types=1);

return new class {
    public function run(\PDO $pdo): void
    {
        $categories = [
            ['name' => 'ropa', 'slug' => 'ropa'],
            ['name' => 'lenceria', 'slug' => 'lenceria'],
            ['name' => 'fotos', 'slug' => 'fotos'],
            ['name' => 'videos', 'slug' => 'videos'],
            ['name' => 'packs', 'slug' => 'packs'],
        ];

        $statement = $pdo->prepare(
            "INSERT INTO categories (name, slug, status)
             VALUES (:name, :slug, 'active')
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                status = VALUES(status)"
        );

        foreach ($categories as $category) {
            $statement->execute($category);
        }
    }
};
