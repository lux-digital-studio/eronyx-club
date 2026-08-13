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
    'session_name' => (string) $env('SESSION_NAME', 'eronyx_session'),
    'csrf_enabled' => true,
    'secure_cookies' => filter_var($env('SECURE_COOKIES', false), FILTER_VALIDATE_BOOLEAN),
    'httponly_cookies' => true,
    'samesite' => (string) $env('COOKIE_SAMESITE', 'Lax'),
];
