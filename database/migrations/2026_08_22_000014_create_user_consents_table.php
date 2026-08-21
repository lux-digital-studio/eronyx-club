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
            "CREATE TABLE IF NOT EXISTS user_consents (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                consent_type VARCHAR(40) NOT NULL,
                document_version VARCHAR(40) NOT NULL,
                accepted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY user_consents_user_type_version_unique (user_id, consent_type, document_version),
                KEY user_consents_user_id_index (user_id),
                KEY user_consents_type_index (consent_type),
                CONSTRAINT user_consents_user_id_foreign FOREIGN KEY (user_id)
                    REFERENCES users (id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS user_consents');
    }
};
