<?php

declare(strict_types=1);

return new class {
    public function transactional(): bool
    {
        return false;
    }

    public function up(\PDO $pdo): void
    {
        $column = $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'users'
                AND COLUMN_NAME = 'session_version'"
        )->fetchColumn();

        if ((int) $column > 0) {
            return;
        }

        $pdo->exec(
            'ALTER TABLE users
             ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status'
        );
    }

    public function down(\PDO $pdo): void
    {
        $column = $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'users'
                AND COLUMN_NAME = 'session_version'"
        )->fetchColumn();

        if ((int) $column === 0) {
            return;
        }

        $pdo->exec('ALTER TABLE users DROP COLUMN session_version');
    }
};
