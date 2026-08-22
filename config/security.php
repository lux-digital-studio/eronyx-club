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

/**
 * Trusted proxies must be explicit IPs. Empty means ignore X-Forwarded-For
 * and X-Forwarded-Proto. Configure before placing ERONYX behind Hostinger
 * or another reverse proxy.
 */
$trustedProxies = $env('TRUSTED_PROXIES', '');
$trustedProxyList = [];

if (is_string($trustedProxies) && $trustedProxies !== '') {
    foreach (explode(',', $trustedProxies) as $proxy) {
        $proxy = trim($proxy);

        if ($proxy !== '' && filter_var($proxy, FILTER_VALIDATE_IP) !== false) {
            $trustedProxyList[] = $proxy;
        }
    }
}

return [
    'session_name' => (string) $env('SESSION_NAME', 'eronyx_session'),
    'csrf_enabled' => true,
    'secure_cookies' => filter_var($env('SECURE_COOKIES', false), FILTER_VALIDATE_BOOLEAN),
    'httponly_cookies' => true,
    'samesite' => (string) $env('COOKIE_SAMESITE', 'Lax'),
    'trusted_proxies' => $trustedProxyList,
    'csp' => "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; media-src 'self'; script-src 'self'; style-src 'self'",
    'hsts' => 'max-age=31536000; includeSubDomains',
    'password_max_length' => 255,
    'rate_limits' => [
        'login' => ['max' => 5, 'decay' => 900],
        'register' => ['max' => 5, 'decay' => 3600],
        'messages' => ['max' => 30, 'decay' => 60],
        'reports' => ['max' => 10, 'decay' => 3600],
        'forgot_password' => ['max' => 5, 'decay' => 3600],
        'reset_password' => ['max' => 10, 'decay' => 1800],
        'change_password' => ['max' => 10, 'decay' => 3600],
        'email_verification_resend' => ['max' => 5, 'decay' => 3600],
        'mfa_challenge' => ['max' => 10, 'decay' => 900],
        'mfa_setup_confirm' => ['max' => 10, 'decay' => 900],
        'mfa_disable' => ['max' => 10, 'decay' => 3600],
        'mfa_recovery_regenerate' => ['max' => 10, 'decay' => 3600],
    ],
];
