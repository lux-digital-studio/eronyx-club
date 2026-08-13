<?php

declare(strict_types=1);

$env = static function (string $key, mixed $default = null): mixed {
    $value = getenv($key);

    if ($value !== false) {
        return $value;
    }

    $envPath = dirname(__DIR__) . '/.env';
    $values = is_file($envPath) ? parse_ini_file($envPath, false, INI_SCANNER_TYPED) : [];

    return is_array($values) && array_key_exists($key, $values) ? $values[$key] : $default;
};

return [
    'driver' => 'mysql',
    'host' => (string) $env('DB_HOST', '127.0.0.1'),
    'port' => (int) $env('DB_PORT', 3306),
    'database' => (string) $env('DB_DATABASE', 'eronyx'),
    'username' => (string) $env('DB_USERNAME', ''),
    'password' => (string) $env('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
];
