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
            "CREATE TABLE IF NOT EXISTS private_content_access (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                listing_id BIGINT UNSIGNED NOT NULL,
                granted_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
                source VARCHAR(40) NOT NULL,
                status VARCHAR(30) NOT NULL,
                granted_at DATETIME NOT NULL,
                expires_at DATETIME NULL DEFAULT NULL,
                revoked_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY private_content_access_user_id_index (user_id),
                KEY private_content_access_listing_id_index (listing_id),
                KEY private_content_access_granted_by_user_id_index (granted_by_user_id),
                KEY private_content_access_status_index (status),
                KEY private_content_access_expires_at_index (expires_at),
                KEY private_content_access_user_listing_status_index (user_id, listing_id, status),
                CONSTRAINT private_content_access_user_id_foreign FOREIGN KEY (user_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE,
                CONSTRAINT private_content_access_listing_id_foreign FOREIGN KEY (listing_id)
                    REFERENCES listings (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE,
                CONSTRAINT private_content_access_granted_by_user_id_foreign FOREIGN KEY (granted_by_user_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE private_content_access');
    }
};
