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
            "ALTER TABLE age_verifications
                ADD COLUMN IF NOT EXISTS provider_status VARCHAR(40) NULL DEFAULT NULL AFTER provider_reference,
                ADD COLUMN IF NOT EXISTS provider_session_expires_at TIMESTAMP NULL DEFAULT NULL AFTER provider_status,
                ADD COLUMN IF NOT EXISTS reviewed_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER provider_session_expires_at,
                ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER reviewed_by_user_id,
                ADD COLUMN IF NOT EXISTS rejection_code VARCHAR(40) NULL DEFAULT NULL AFTER reviewed_at,
                ADD COLUMN IF NOT EXISTS metadata_json LONGTEXT NULL DEFAULT NULL AFTER rejection_code"
        );

        $indexExists = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
                AND table_name = 'age_verifications'
                AND index_name = 'age_verifications_reviewed_by_user_id_index'"
        )->fetchColumn();

        if ($indexExists === 0) {
            $pdo->exec(
                'ALTER TABLE age_verifications
                    ADD KEY age_verifications_reviewed_by_user_id_index (reviewed_by_user_id)'
            );
        }

        $fkExists = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.table_constraints
             WHERE table_schema = DATABASE()
                AND table_name = 'age_verifications'
                AND constraint_name = 'age_verifications_reviewed_by_user_id_foreign'"
        )->fetchColumn();

        if ($fkExists === 0) {
            $pdo->exec(
                'ALTER TABLE age_verifications
                    ADD CONSTRAINT age_verifications_reviewed_by_user_id_foreign
                    FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE'
            );
        }

        $sessionIndex = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
                AND table_name = 'age_verifications'
                AND index_name = 'age_verifications_session_expires_index'"
        )->fetchColumn();

        if ($sessionIndex === 0) {
            $pdo->exec(
                'ALTER TABLE age_verifications
                    ADD KEY age_verifications_session_expires_index (provider_session_expires_at)'
            );
        }
    }

    public function down(\PDO $pdo): void
    {
        $fkExists = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.table_constraints
             WHERE table_schema = DATABASE()
                AND table_name = 'age_verifications'
                AND constraint_name = 'age_verifications_reviewed_by_user_id_foreign'"
        )->fetchColumn();

        if ($fkExists > 0) {
            $pdo->exec('ALTER TABLE age_verifications DROP FOREIGN KEY age_verifications_reviewed_by_user_id_foreign');
        }

        $pdo->exec(
            'ALTER TABLE age_verifications
                DROP COLUMN IF EXISTS metadata_json,
                DROP COLUMN IF EXISTS rejection_code,
                DROP COLUMN IF EXISTS reviewed_at,
                DROP COLUMN IF EXISTS reviewed_by_user_id,
                DROP COLUMN IF EXISTS provider_session_expires_at,
                DROP COLUMN IF EXISTS provider_status'
        );
    }
};
