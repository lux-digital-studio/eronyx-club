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
    // Pending TOTP secrets stay in user_mfa.status=pending (encrypted). Enable only after a valid code.
    // Clock drift: ±1 period (30s). Password reset does not disable MFA.
    // Lost authenticator + recovery codes: no admin bypass in MFA-1.
    'issuer' => 'ERONYX',
    'digits' => 6,
    'period' => 30,
    'algorithm' => 'sha1',
    'window' => 1,
    'recovery_code_count' => 10,
    'recovery_code_groups' => 3,
    'recovery_code_group_length' => 4,
    'max_attempts' => 10,
    'rate_windows' => [
        'challenge' => 900,
        'setup_confirm' => 900,
        'disable' => 3600,
        'recovery_regenerate' => 3600,
    ],
    'encryption_key' => (string) $env('MFA_ENCRYPTION_KEY', ''),
];
