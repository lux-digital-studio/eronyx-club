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
            "CREATE TABLE IF NOT EXISTS orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                buyer_user_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'pending',
                subtotal_amount DECIMAL(12,2) NOT NULL,
                total_amount DECIMAL(12,2) NOT NULL,
                currency CHAR(3) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY orders_buyer_user_id_index (buyer_user_id),
                KEY orders_status_index (status),
                KEY orders_created_at_index (created_at),
                KEY orders_deleted_at_index (deleted_at),
                CONSTRAINT orders_buyer_user_id_foreign FOREIGN KEY (buyer_user_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE,
                CONSTRAINT orders_subtotal_amount_check CHECK (subtotal_amount >= 0),
                CONSTRAINT orders_total_amount_check CHECK (total_amount >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS order_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                listing_id BIGINT UNSIGNED NOT NULL,
                seller_user_id BIGINT UNSIGNED NOT NULL,
                title_snapshot VARCHAR(180) NOT NULL,
                unit_price DECIMAL(12,2) NOT NULL,
                quantity INT UNSIGNED NOT NULL DEFAULT 1,
                total_amount DECIMAL(12,2) NOT NULL,
                currency CHAR(3) NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY order_items_order_id_index (order_id),
                KEY order_items_listing_id_index (listing_id),
                KEY order_items_seller_user_id_index (seller_user_id),
                KEY order_items_status_index (status),
                CONSTRAINT order_items_order_id_foreign FOREIGN KEY (order_id)
                    REFERENCES orders (id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE,
                CONSTRAINT order_items_listing_id_foreign FOREIGN KEY (listing_id)
                    REFERENCES listings (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE,
                CONSTRAINT order_items_seller_user_id_foreign FOREIGN KEY (seller_user_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE,
                CONSTRAINT order_items_unit_price_check CHECK (unit_price >= 0),
                CONSTRAINT order_items_quantity_check CHECK (quantity > 0),
                CONSTRAINT order_items_total_amount_check CHECK (total_amount >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS payments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                provider VARCHAR(80) NOT NULL,
                external_id VARCHAR(255) NULL DEFAULT NULL,
                amount DECIMAL(12,2) NOT NULL,
                currency CHAR(3) NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'pending',
                paid_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY payments_provider_external_id_unique (provider, external_id),
                KEY payments_order_id_index (order_id),
                KEY payments_status_index (status),
                CONSTRAINT payments_order_id_foreign FOREIGN KEY (order_id)
                    REFERENCES orders (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE,
                CONSTRAINT payments_amount_check CHECK (amount >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE payments');
        $pdo->exec('DROP TABLE order_items');
        $pdo->exec('DROP TABLE orders');
    }
};
