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
            "CREATE TABLE IF NOT EXISTS conversations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                listing_id BIGINT UNSIGNED NULL DEFAULT NULL,
                created_by_user_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                last_message_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY conversations_listing_id_index (listing_id),
                KEY conversations_status_index (status),
                KEY conversations_last_message_at_index (last_message_at),
                UNIQUE KEY conversations_listing_created_by_unique (listing_id, created_by_user_id),
                CONSTRAINT conversations_listing_id_foreign FOREIGN KEY (listing_id)
                    REFERENCES listings (id)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE,
                CONSTRAINT conversations_created_by_user_id_foreign FOREIGN KEY (created_by_user_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE,
                CONSTRAINT conversations_status_check CHECK (status IN ('active', 'closed'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id BIGINT UNSIGNED NOT NULL,
                sender_user_id BIGINT UNSIGNED NOT NULL,
                body VARCHAR(2000) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY messages_conversation_id_index (conversation_id),
                KEY messages_sender_user_id_index (sender_user_id),
                KEY messages_created_at_index (created_at),
                CONSTRAINT messages_conversation_id_foreign FOREIGN KEY (conversation_id)
                    REFERENCES conversations (id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE,
                CONSTRAINT messages_sender_user_id_foreign FOREIGN KEY (sender_user_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS conversation_participants (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                last_read_message_id BIGINT UNSIGNED NULL DEFAULT NULL,
                joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY conversation_participants_conversation_user_unique (conversation_id, user_id),
                KEY conversation_participants_user_id_index (user_id),
                KEY conversation_participants_last_read_message_id_index (last_read_message_id),
                CONSTRAINT conversation_participants_conversation_id_foreign FOREIGN KEY (conversation_id)
                    REFERENCES conversations (id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE,
                CONSTRAINT conversation_participants_user_id_foreign FOREIGN KEY (user_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE,
                CONSTRAINT conversation_participants_last_read_message_id_foreign FOREIGN KEY (last_read_message_id)
                    REFERENCES messages (id)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS conversation_participants');
        $pdo->exec('DROP TABLE IF EXISTS messages');
        $pdo->exec('DROP TABLE IF EXISTS conversations');
    }
};
