<?php

declare(strict_types=1);

return new class {
    public function transactional(): bool
    {
        return false;
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                actor_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
                type VARCHAR(60) NOT NULL,
                title VARCHAR(180) NOT NULL,
                body VARCHAR(500) NULL DEFAULT NULL,
                entity_type VARCHAR(40) NULL DEFAULT NULL,
                entity_id BIGINT UNSIGNED NULL DEFAULT NULL,
                action_url VARCHAR(255) NULL DEFAULT NULL,
                dedupe_key VARCHAR(180) NULL DEFAULT NULL,
                read_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY notifications_user_created_index (user_id, created_at, id),
                KEY notifications_user_read_at_index (user_id, read_at),
                UNIQUE KEY notifications_dedupe_key_unique (dedupe_key),
                CONSTRAINT notifications_user_id_foreign FOREIGN KEY (user_id)
                    REFERENCES users (id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE,
                CONSTRAINT notifications_actor_user_id_foreign FOREIGN KEY (actor_user_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE,
                CONSTRAINT notifications_type_check CHECK (type IN (
                    'new_message',
                    'listing_favorited',
                    'creator_application_approved',
                    'creator_application_rejected',
                    'listing_approved',
                    'listing_rejected',
                    'listing_suspended',
                    'listing_restored',
                    'creator_suspended',
                    'creator_restored',
                    'order_completed',
                    'order_paid',
                    'report_updated'
                ))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS notifications');
    }
};
