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

$mailer = strtolower(trim((string) $env('MAIL_MAILER', 'array')));
$mailer = in_array($mailer, ['smtp', 'array'], true) ? $mailer : 'array';

$encryption = strtolower(trim((string) $env('MAIL_ENCRYPTION', 'tls')));
$encryption = in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls';

$port = (int) $env('MAIL_PORT', 587);
if ($port < 1 || $port > 65535) {
    $port = 587;
}

$timeout = (int) $env('MAIL_TIMEOUT', 10);
if ($timeout < 1 || $timeout > 60) {
    $timeout = 10;
}

$fromName = trim((string) $env('MAIL_FROM_NAME', 'ERONYX'));
if ($fromName === '') {
    $fromName = 'ERONYX';
}

return [
    'mailer' => $mailer,
    'host' => trim((string) $env('MAIL_HOST', '')),
    'port' => $port,
    'username' => (string) $env('MAIL_USERNAME', ''),
    'password' => (string) $env('MAIL_PASSWORD', ''),
    'encryption' => $encryption,
    'from_address' => trim((string) $env('MAIL_FROM_ADDRESS', '')),
    'from_name' => $fromName,
    'timeout' => $timeout,
];
