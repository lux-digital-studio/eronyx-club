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

$mode = strtolower(trim((string) $env('VERIFICATION_MODE', 'manual_review')));
$allowedModes = ['self_declaration', 'manual_review', 'provider'];

if (!in_array($mode, $allowedModes, true)) {
    $mode = 'manual_review';
}

$ttl = (int) $env('VERIFICATION_SESSION_TTL', 86400);

if ($ttl < 60) {
    $ttl = 86400;
}

return [
    'mode' => $mode,
    'provider' => strtolower(trim((string) $env('VERIFICATION_PROVIDER', ''))),
    'session_ttl' => $ttl,
    'require_verified_for_creator_activation' => filter_var(
        $env('VERIFICATION_REQUIRE_FOR_CREATOR', true),
        FILTER_VALIDATE_BOOLEAN
    ),
];
