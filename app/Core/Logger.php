<?php

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'password_hash',
        '_csrf',
        'csrf',
        'cookie',
        'cookies',
        'authorization',
        'session',
        'phpsessid',
        'eronyx_session',
        'card',
        'pan',
        'cvv',
        'cvc',
        'iban',
        'secret',
        'token',
    ];

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        $directory = dirname(__DIR__, 2) . '/storage/logs';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $line = sprintf(
            "[%s] %s %s %s\n",
            gmdate('c'),
            strtoupper($level),
            self::redact($message),
            $context === [] ? '' : json_encode(self::sanitize($context), JSON_UNESCAPED_UNICODE)
        );

        @file_put_contents(
            $directory . '/app-' . gmdate('Y-m-d') . '.log',
            $line,
            FILE_APPEND | LOCK_EX
        );
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private static function sanitize(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $normalized = strtolower((string) $key);

            if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
                $clean[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::sanitize($value);
                continue;
            }

            if (is_string($value)) {
                $clean[$key] = self::redact($value);
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private static function redact(string $value): string
    {
        return (string) preg_replace(
            '/(password(?:_hash|_confirmation)?|_csrf|csrf|cookie|authorization)\\s*[=:]\\s*\\S+/i',
            '$1=[redacted]',
            $value
        );
    }
}
