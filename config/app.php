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
    'name' => (string) $env('APP_NAME', 'ERONYX'),
    'env' => (string) $env('APP_ENV', 'local'),
    'debug' => filter_var($env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
    'url' => (string) $env('APP_URL', 'http://localhost/eronyx/public'),
];
