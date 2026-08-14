<?php

declare(strict_types=1);

use App\Core\Database;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$pdo = (new Database())->connection();
$seedFiles = glob(__DIR__ . '/seeds/*.php') ?: [];
sort($seedFiles);

foreach ($seedFiles as $seedFile) {
    $seedName = basename($seedFile, '.php');
    $seed = require $seedFile;

    try {
        $pdo->beginTransaction();
        $seed->run($pdo);
        $pdo->commit();

        echo "Seeded: {$seedName}" . PHP_EOL;
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        fwrite(STDERR, "Seed failed: {$seedName}" . PHP_EOL);
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

echo 'Seeds complete.' . PHP_EOL;
