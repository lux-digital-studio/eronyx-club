<?php

declare(strict_types=1);

return new class {
    public function transactional(): bool
    {
        return false;
    }

    public function up(\PDO $pdo): void
    {
        $exists = $pdo->query(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'listings'
                AND CONSTRAINT_NAME = 'listings_status_check'
             LIMIT 1"
        )->fetchColumn();

        if ($exists !== false) {
            return;
        }

        $pdo->exec(
            "ALTER TABLE listings
             ADD CONSTRAINT listings_status_check
             CHECK (status IN ('draft', 'pending_review', 'published', 'rejected', 'suspended'))"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE listings DROP CHECK listings_status_check');
    }
};
