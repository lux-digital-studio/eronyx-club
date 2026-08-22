<?php

declare(strict_types=1);

use App\Core\EnvironmentValidator;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$explicitMode = false;
$mode = 'local';

foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--production') {
        $mode = 'production';
        $explicitMode = true;
    } elseif ($arg === '--staging') {
        $mode = 'staging';
        $explicitMode = true;
    } elseif ($arg === '--local') {
        $mode = 'local';
        $explicitMode = true;
    }
}

if (!$explicitMode) {
    $app = require $root . '/config/app.php';
    $envName = strtolower(trim((string) ($app['env'] ?? 'local')));
    if (in_array($envName, ['production', 'staging'], true)) {
        $mode = $envName;
    }
}

$validator = EnvironmentValidator::fromProject($root);
$results = $validator->run($mode);
echo $validator->format($results);

$fails = 0;
$warns = 0;
foreach ($results as $result) {
    if ($result['status'] === EnvironmentValidator::FAIL) {
        $fails++;
    }
    if ($result['status'] === EnvironmentValidator::WARN) {
        $warns++;
    }
}

echo sprintf("mode=%s FAIL=%d WARN=%d\n", $mode, $fails, $warns);

exit($validator->failed($results) ? 1 : 0);
