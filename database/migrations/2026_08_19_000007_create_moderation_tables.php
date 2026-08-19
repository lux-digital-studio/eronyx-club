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
            "CREATE TABLE IF NOT EXISTS reports (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                reporter_user_id BIGINT UNSIGNED NOT NULL,
                target_type VARCHAR(20) NOT NULL,
                target_id BIGINT UNSIGNED NOT NULL,
                reason_code VARCHAR(40) NOT NULL,
                details VARCHAR(2000) NULL DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                resolved_at TIMESTAMP NULL DEFAULT NULL,
                resolved_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                open_duplicate_key VARCHAR(120) AS (
                    CASE
                        WHEN status IN ('open', 'in_review')
                            THEN CONCAT(CAST(reporter_user_id AS CHAR), ':', target_type, ':', CAST(target_id AS CHAR))
                        ELSE NULL
                    END
                ) PERSISTENT,
                PRIMARY KEY (id),
                KEY reports_reporter_user_id_index (reporter_user_id),
                KEY reports_status_index (status),
                KEY reports_target_index (target_type, target_id),
                KEY reports_created_at_index (created_at),
                KEY reports_resolved_by_user_id_index (resolved_by_user_id),
                UNIQUE KEY reports_open_duplicate_unique (open_duplicate_key),
                CONSTRAINT reports_reporter_user_id_foreign FOREIGN KEY (reporter_user_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE,
                CONSTRAINT reports_resolved_by_user_id_foreign FOREIGN KEY (resolved_by_user_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE,
                CONSTRAINT reports_target_type_check CHECK (target_type IN ('listing', 'user', 'message')),
                CONSTRAINT reports_status_check CHECK (status IN ('open', 'in_review', 'resolved', 'dismissed')),
                CONSTRAINT reports_reason_code_check CHECK (reason_code IN (
                    'spam',
                    'scam',
                    'harassment',
                    'illegal_content',
                    'underage_concern',
                    'non_consensual_content',
                    'misleading',
                    'prohibited_item',
                    'other'
                ))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS moderation_actions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                moderator_user_id BIGINT UNSIGNED NOT NULL,
                target_type VARCHAR(20) NOT NULL,
                target_id BIGINT UNSIGNED NOT NULL,
                action_type VARCHAR(40) NOT NULL,
                reason_code VARCHAR(40) NULL DEFAULT NULL,
                notes VARCHAR(2000) NULL DEFAULT NULL,
                previous_status VARCHAR(40) NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY moderation_actions_moderator_user_id_index (moderator_user_id),
                KEY moderation_actions_target_index (target_type, target_id),
                KEY moderation_actions_action_type_index (action_type),
                KEY moderation_actions_created_at_index (created_at),
                CONSTRAINT moderation_actions_moderator_user_id_foreign FOREIGN KEY (moderator_user_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE,
                CONSTRAINT moderation_actions_target_type_check CHECK (target_type IN ('listing', 'user', 'message', 'report')),
                CONSTRAINT moderation_actions_action_type_check CHECK (action_type IN (
                    'listing_suspend',
                    'listing_restore',
                    'creator_suspend',
                    'creator_restore',
                    'report_resolve',
                    'report_dismiss',
                    'message_flag'
                ))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                actor_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
                event_type VARCHAR(60) NOT NULL,
                entity_type VARCHAR(40) NOT NULL,
                entity_id BIGINT UNSIGNED NULL DEFAULT NULL,
                metadata_json LONGTEXT NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY audit_logs_actor_user_id_index (actor_user_id),
                KEY audit_logs_event_type_index (event_type),
                KEY audit_logs_entity_index (entity_type, entity_id),
                KEY audit_logs_created_at_index (created_at),
                CONSTRAINT audit_logs_actor_user_id_foreign FOREIGN KEY (actor_user_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS audit_logs');
        $pdo->exec('DROP TABLE IF EXISTS moderation_actions');
        $pdo->exec('DROP TABLE IF EXISTS reports');
    }
};
