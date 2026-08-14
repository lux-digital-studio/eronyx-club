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
            "CREATE TABLE categories (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                parent_id BIGINT UNSIGNED NULL DEFAULT NULL,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY categories_slug_unique (slug),
                KEY categories_parent_id_index (parent_id),
                KEY categories_status_index (status),
                CONSTRAINT categories_parent_id_foreign FOREIGN KEY (parent_id)
                    REFERENCES categories (id)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE media_files (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                owner_user_id BIGINT UNSIGNED NOT NULL,
                storage_disk VARCHAR(50) NOT NULL,
                storage_key VARCHAR(500) NOT NULL,
                media_type VARCHAR(40) NOT NULL,
                visibility VARCHAR(30) NOT NULL,
                mime_type VARCHAR(120) NULL DEFAULT NULL,
                size_bytes BIGINT UNSIGNED NULL DEFAULT NULL,
                checksum VARCHAR(128) NULL DEFAULT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY media_files_storage_unique (storage_disk, storage_key),
                KEY media_files_owner_user_id_index (owner_user_id),
                KEY media_files_media_type_index (media_type),
                KEY media_files_visibility_index (visibility),
                KEY media_files_status_index (status),
                KEY media_files_deleted_at_index (deleted_at),
                CONSTRAINT media_files_owner_user_id_foreign FOREIGN KEY (owner_user_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE listings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                owner_user_id BIGINT UNSIGNED NOT NULL,
                creator_profile_id BIGINT UNSIGNED NULL DEFAULT NULL,
                title VARCHAR(180) NOT NULL,
                slug VARCHAR(220) NOT NULL,
                description TEXT NULL DEFAULT NULL,
                listing_type VARCHAR(40) NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'draft',
                price DECIMAL(12,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'EUR',
                visibility VARCHAR(30) NOT NULL DEFAULT 'public',
                published_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY listings_slug_unique (slug),
                KEY listings_owner_user_id_index (owner_user_id),
                KEY listings_creator_profile_id_index (creator_profile_id),
                KEY listings_status_index (status),
                KEY listings_listing_type_index (listing_type),
                KEY listings_visibility_index (visibility),
                KEY listings_published_at_index (published_at),
                KEY listings_price_index (price),
                KEY listings_deleted_at_index (deleted_at),
                CONSTRAINT listings_owner_user_id_foreign FOREIGN KEY (owner_user_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE,
                CONSTRAINT listings_creator_profile_id_foreign FOREIGN KEY (creator_profile_id)
                    REFERENCES creator_profiles (id)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE,
                CONSTRAINT listings_price_check CHECK (price >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE listing_categories (
                listing_id BIGINT UNSIGNED NOT NULL,
                category_id BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (listing_id, category_id),
                KEY listing_categories_category_id_index (category_id),
                CONSTRAINT listing_categories_listing_id_foreign FOREIGN KEY (listing_id)
                    REFERENCES listings (id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE,
                CONSTRAINT listing_categories_category_id_foreign FOREIGN KEY (category_id)
                    REFERENCES categories (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE listing_media (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                listing_id BIGINT UNSIGNED NOT NULL,
                media_file_id BIGINT UNSIGNED NOT NULL,
                usage_type VARCHAR(40) NOT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY listing_media_listing_media_unique (listing_id, media_file_id),
                KEY listing_media_listing_id_index (listing_id),
                KEY listing_media_media_file_id_index (media_file_id),
                KEY listing_media_usage_type_index (usage_type),
                KEY listing_media_sort_order_index (sort_order),
                CONSTRAINT listing_media_listing_id_foreign FOREIGN KEY (listing_id)
                    REFERENCES listings (id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE,
                CONSTRAINT listing_media_media_file_id_foreign FOREIGN KEY (media_file_id)
                    REFERENCES media_files (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec('CREATE INDEX profiles_avatar_media_id_index ON profiles (avatar_media_id)');
        $pdo->exec(
            "ALTER TABLE profiles
                ADD CONSTRAINT profiles_avatar_media_id_foreign FOREIGN KEY (avatar_media_id)
                REFERENCES media_files (id)
                ON DELETE SET NULL
                ON UPDATE CASCADE"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE profiles DROP FOREIGN KEY profiles_avatar_media_id_foreign');
        $pdo->exec('DROP INDEX profiles_avatar_media_id_index ON profiles');
        $pdo->exec('DROP TABLE listing_media');
        $pdo->exec('DROP TABLE listing_categories');
        $pdo->exec('DROP TABLE listings');
        $pdo->exec('DROP TABLE media_files');
        $pdo->exec('DROP TABLE categories');
    }
};
