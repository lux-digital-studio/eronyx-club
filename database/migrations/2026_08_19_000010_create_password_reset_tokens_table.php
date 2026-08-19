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
            "CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL DEFAULT NULL,
                requested_ip_hash CHAR(64) NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY password_reset_tokens_token_hash_unique (token_hash),
                KEY password_reset_tokens_user_id_index (user_id),
                KEY password_reset_tokens_expires_at_index (expires_at),
                CONSTRAINT password_reset_tokens_user_id_foreign FOREIGN KEY (user_id)
                    REFERENCES users (id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS password_reset_tokens');
    }
};
