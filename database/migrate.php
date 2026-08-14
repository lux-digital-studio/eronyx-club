<?php

declare(strict_types=1);

use App\Core\Database;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$pdo = (new Database())->connection();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS migrations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        migration VARCHAR(255) NOT NULL,
        batch INT UNSIGNED NOT NULL,
        executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY migrations_migration_unique (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$executed = $pdo->query('SELECT migration FROM migrations')
    ->fetchAll(\PDO::FETCH_COLUMN);
$executed = array_flip($executed);

$migrationFiles = glob(__DIR__ . '/migrations/*.php') ?: [];
sort($migrationFiles);

$nextBatch = (int) $pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')
    ->fetchColumn();

foreach ($migrationFiles as $migrationFile) {
    $migrationName = basename($migrationFile, '.php');

    if (isset($executed[$migrationName])) {
        continue;
    }

    $migration = require $migrationFile;
    $transactional = method_exists($migration, 'transactional') ? $migration->transactional() : true;

    try {
        if ($transactional) {
            $pdo->beginTransaction();
        }

        $migration->up($pdo);

        $statement = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)');
        $statement->execute([
            'migration' => $migrationName,
            'batch' => $nextBatch,
        ]);

        if ($transactional) {
            $pdo->commit();
        }

        echo "Migrated: {$migrationName}" . PHP_EOL;
    } catch (Throwable $exception) {
        if ($transactional && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        fwrite(STDERR, "Migration failed: {$migrationName}" . PHP_EOL);
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

echo 'Migrations complete.' . PHP_EOL;
