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

$placeholder = static function (string $key, mixed $value): string {
    $text = trim((string) $value);

    return $text !== '' ? $text : '[PENDIENTE DE REVISIÓN LEGAL: ' . $key . ']';
};

return [
    'status' => 'draft',
    'notice' => 'Borrador técnico. Requiere revisión jurídica profesional antes de producción. No constituye asesoramiento jurídico definitivo.',
    'terms_version' => (string) $env('LEGAL_TERMS_VERSION', '2026-08-22'),
    'privacy_version' => (string) $env('LEGAL_PRIVACY_VERSION', '2026-08-22'),
    'cookies_version' => (string) $env('LEGAL_COOKIES_VERSION', '2026-08-22'),
    'content_policy_version' => (string) $env('LEGAL_CONTENT_POLICY_VERSION', '2026-08-22'),
    'creator_rules_version' => (string) $env('LEGAL_CREATOR_RULES_VERSION', '2026-08-22'),
    'age_policy_version' => (string) $env('LEGAL_AGE_POLICY_VERSION', '2026-08-22'),
    'reporting_policy_version' => (string) $env('LEGAL_REPORTING_POLICY_VERSION', '2026-08-22'),
    'business_name' => $placeholder('business_name', $env('LEGAL_BUSINESS_NAME', '')),
    'legal_email' => $placeholder('legal_email', $env('LEGAL_EMAIL', '')),
    'privacy_email' => $placeholder('privacy_email', $env('PRIVACY_EMAIL', '')),
    'jurisdiction' => $placeholder('jurisdiction', $env('LEGAL_JURISDICTION', '')),
];
