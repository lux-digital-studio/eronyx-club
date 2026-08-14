<?php

declare(strict_types=1);

return new class {
    public function run(\PDO $pdo): void
    {
        $roles = [
            'buyer' => 'Marketplace buyer',
            'creator' => 'Marketplace creator and seller',
            'moderator' => 'Platform moderator',
            'admin' => 'Platform administrator',
        ];

        $exists = $pdo->prepare('SELECT 1 FROM roles WHERE name = :name LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT INTO roles (name, description) VALUES (:name, :description)'
        );

        foreach ($roles as $name => $description) {
            $exists->execute(['name' => $name]);

            if ($exists->fetchColumn() !== false) {
                continue;
            }

            $insert->execute([
                'name' => $name,
                'description' => $description,
            ]);
        }
    }
};
