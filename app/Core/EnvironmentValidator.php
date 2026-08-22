<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\MfaCrypto;
use Throwable;

final class EnvironmentValidator
{
    public const PASS = 'PASS';
    public const WARN = 'WARN';
    public const FAIL = 'FAIL';

    /** @var array<string, mixed> */
    private array $app;

    /** @var array<string, mixed> */
    private array $mail;

    /** @var array<string, mixed> */
    private array $mfa;

    /** @var array<string, mixed> */
    private array $verification;

    /** @var array<string, mixed> */
    private array $database;

    /** @var array<string, mixed> */
    private array $legal;

    private string $root;

    /**
     * @param array<string, mixed> $app
     * @param array<string, mixed> $mail
     * @param array<string, mixed> $mfa
     * @param array<string, mixed> $verification
     * @param array<string, mixed> $database
     * @param array<string, mixed> $legal
     */
    public function __construct(
        array $app,
        array $mail,
        array $mfa,
        array $verification,
        array $database,
        array $legal,
        string $root
    ) {
        $this->app = $app;
        $this->mail = $mail;
        $this->mfa = $mfa;
        $this->verification = $verification;
        $this->database = $database;
        $this->legal = $legal;
        $this->root = rtrim($root, '/\\');
    }

    public static function fromProject(string $root): self
    {
        $root = rtrim($root, '/\\');

        return new self(
            require $root . '/config/app.php',
            require $root . '/config/mail.php',
            require $root . '/config/mfa.php',
            require $root . '/config/verification.php',
            require $root . '/config/database.php',
            require $root . '/config/legal.php',
            $root
        );
    }

    public static function currentEnv(): string
    {
        $value = getenv('APP_ENV');
        if (is_string($value) && trim($value) !== '') {
            return strtolower(trim($value));
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        $values = is_file($envPath) ? parse_ini_file($envPath, false, INI_SCANNER_TYPED) : [];
        $fromFile = is_array($values) && isset($values['APP_ENV']) ? strtolower(trim((string) $values['APP_ENV'])) : 'local';

        return $fromFile !== '' ? $fromFile : 'local';
    }

    public static function allowsTestPayment(string $env): bool
    {
        return strtolower(trim($env)) === 'local';
    }

    public static function allowsDevResetUrl(string $env): bool
    {
        return in_array(strtolower(trim($env)), ['local', 'test'], true);
    }

    public static function allowsTestVerificationProvider(string $env): bool
    {
        return !in_array(strtolower(trim($env)), ['production', 'staging'], true);
    }

    /**
     * @return list<array{status: string, code: string, message: string}>
     */
    public function run(string $mode): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['local', 'test', 'staging', 'production'], true)) {
            $mode = 'local';
        }

        $results = [];
        $this->checkPhp($results);
        $this->checkExtensions($results);
        $this->checkComposerLock($results);
        $this->checkStorage($results);
        $this->checkDebug($results, $mode);
        $this->checkAppUrl($results, $mode);
        $this->checkMail($results, $mode);
        $this->checkMfaKey($results, $mode);
        $this->checkDatabase($results, $mode);
        $this->checkVerification($results, $mode);
        $this->checkLegal($results, $mode);
        $this->checkTestPayPolicy($results, $mode);

        return $results;
    }

    /** @param list<array{status: string, code: string, message: string}> $results */
    public function failed(array $results): bool
    {
        foreach ($results as $result) {
            if ($result['status'] === self::FAIL) {
                return true;
            }
        }

        return false;
    }

    public function format(array $results): string
    {
        $lines = [];

        foreach ($results as $result) {
            $lines[] = $result['status'] . ' ' . $result['message'];
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param list<array{status: string, code: string, message: string}> $results */
    private function checkPhp(array &$results): void
    {
        $ok = PHP_VERSION_ID >= 80200;
        $results[] = $this->item(
            $ok ? self::PASS : self::FAIL,
            'php_version',
            $ok ? 'PHP >= 8.2' : 'PHP 8.2 or newer is required'
        );
    }

    /** @param list<array{status: string, code: string, message: string}> $results */
    private function checkExtensions(array &$results): void
    {
        $required = ['pdo_mysql', 'openssl', 'mbstring', 'fileinfo', 'json', 'session', 'ctype', 'filter', 'hash'];

        foreach ($required as $extension) {
            $loaded = extension_loaded($extension);
            $results[] = $this->item(
                $loaded ? self::PASS : self::FAIL,
                'ext_' . $extension,
                $loaded ? 'extension ' . $extension : 'missing extension ' . $extension
            );
        }
    }

    /** @param list<array{status: string, code: string, message: string}> $results */
    private function checkComposerLock(array &$results): void
    {
        $ok = is_file($this->root . '/composer.lock');
        $results[] = $this->item(
            $ok ? self::PASS : self::FAIL,
            'composer_lock',
            $ok ? 'composer.lock present' : 'composer.lock missing'
        );
    }

    /** @param list<array{status: string, code: string, message: string}> $results */
    private function checkStorage(array &$results): void
    {
        $paths = [
            $this->root . '/storage/logs',
            $this->root . '/storage/cache',
            $this->root . '/storage/private/media',
        ];

        foreach ($paths as $path) {
            $writable = is_dir($path) ? is_writable($path) : is_writable(dirname($path));
            $results[] = $this->item(
                $writable ? self::PASS : self::FAIL,
                'writable_' . basename($path),
                $writable ? 'writable ' . $this->relative($path) : 'not writable ' . $this->relative($path)
            );
        }

        $private = $this->root . '/storage/private';
        $public = $this->root . '/public';
        $privateReal = realpath($private) ?: $private;
        $publicReal = realpath($public) ?: $public;
        $nested = str_starts_with(
            strtolower(str_replace('\\', '/', $privateReal)),
            strtolower(str_replace('\\', '/', $publicReal)) . '/'
        );
        $results[] = $this->item(
            $nested ? self::FAIL : self::PASS,
            'private_storage_location',
            $nested ? 'private storage must not live under public/' : 'private storage is outside public/'
        );
    }

    /** @param list<array{status: string, code: string, message: string}> $results */
    private function checkDebug(array &$results, string $mode): void
    {
        $debug = (bool) ($this->app['debug'] ?? false);

        if (in_array($mode, ['production', 'staging'], true) && $debug) {
            $results[] = $this->item(self::FAIL, 'app_debug', 'APP_DEBUG must be false outside local/test');
            return;
        }

        $results[] = $this->item(self::PASS, 'app_debug', $debug ? 'APP_DEBUG enabled (non-production)' : 'APP_DEBUG disabled');
    }

    /** @param list<array{status: string, code: string, message: string}> $results */
    private function checkAppUrl(array &$results, string $mode): void
    {
        $url = trim((string) ($this->app['url'] ?? ''));
        $https = str_starts_with(strtolower($url), 'https://');

        if ($mode === 'production' && !$https) {
            $results[] = $this->item(self::FAIL, 'app_url', 'APP_URL must use HTTPS in production');
            return;
        }

        if ($mode === 'staging' && !$https) {
            $results[] = $this->item(self::WARN, 'app_url', 'APP_URL should use HTTPS in staging');
            return;
        }

        $results[] = $this->item(self::PASS, 'app_url', 'APP_URL scheme acceptable for ' . $mode);
    }

    /** @param list<array{status: string, code: string, message: string}> $results */
    private function checkMail(array &$results, string $mode): void
    {
        $mailer = strtolower(trim((string) ($this->mail['mailer'] ?? 'array')));

        if (in_array($mode, ['production', 'staging'], true) && $mailer !== 'smtp') {
            $results[] = $this->item(self::FAIL, 'mail_mailer', 'MAIL_MAILER=array is not valid for ' . $mode);
            return;
        }

        if (in_array($mode, ['production', 'staging'], true)) {
            $host = trim((string) ($this->mail['host'] ?? ''));
            $from = trim((string) ($this->mail['from_address'] ?? ''));
            if ($host === '' || $from === '') {
                $results[] = $this->item(self::FAIL, 'mail_config', 'MAIL_HOST and MAIL_FROM_ADDRESS are required for ' . $mode);
                return;
            }
        }

        $results[] = $this->item(self::PASS, 'mail_mailer', 'mailer acceptable for ' . $mode);
    }

    /** @param list<array{status: string, code: string, message: string}> $results */
    private function checkMfaKey(array &$results, string $mode): void
    {
        $valid = false;

        try {
            $valid = strlen((new MfaCrypto($this->mfa))->keyBytes()) === 32;
        } catch (Throwable) {
            $valid = false;
        }

        if (in_array($mode, ['production', 'staging'], true) && !$valid) {
            $results[] = $this->item(self::FAIL, 'mfa_key', 'MFA_ENCRYPTION_KEY missing or invalid');
            return;
        }

        if (!$valid) {
            $results[] = $this->item(self::WARN, 'mfa_key', 'MFA_ENCRYPTION_KEY not configured (required before TOTP setup)');
            return;
        }

        $results[] = $this->item(self::PASS, 'mfa_key', 'MFA_ENCRYPTION_KEY present');
    }

    /** @param list<array{status: string, code: string, message: string}> $results */
    private function checkDatabase(array &$results, string $mode): void
    {
        $name = trim((string) ($this->database['database'] ?? ''));
        $user = trim((string) ($this->database['username'] ?? ''));

        if (in_array($mode, ['production', 'staging'], true) && ($name === '' || $user === '')) {
            $results[] = $this->item(self::FAIL, 'database', 'DB_DATABASE and DB_USERNAME are required');
            return;
        }

        $results[] = $this->item(self::PASS, 'database', 'database config present');
    }

    /** @param list<array{status: string, code: string, message: string}> $results */
    private function checkVerification(array &$results, string $mode): void
    {
        $verificationMode = strtolower(trim((string) ($this->verification['mode'] ?? 'manual_review')));
        $provider = strtolower(trim((string) ($this->verification['provider'] ?? '')));

        if ($verificationMode === 'provider' && $provider === '') {
            $level = in_array($mode, ['production', 'staging'], true) ? self::FAIL : self::WARN;
            $results[] = $this->item($level, 'verification_provider', 'VERIFICATION_MODE=provider requires a configured provider');
            return;
        }

        if ($provider === 'test' && !self::allowsTestVerificationProvider($mode)) {
            $results[] = $this->item(self::FAIL, 'verification_test_provider', 'test age-verification provider is not allowed in ' . $mode);
            return;
        }

        $results[] = $this->item(self::PASS, 'verification', 'verification config acceptable for ' . $mode);
    }

    /** @param list<array{status: string, code: string, message: string}> $results */
    private function checkLegal(array &$results, string $mode): void
    {
        $placeholders = 0;

        foreach (['business_name', 'legal_email', 'privacy_email', 'jurisdiction'] as $key) {
            $value = (string) ($this->legal[$key] ?? '');
            if ($value === '' || str_contains($value, '[PENDIENTE')) {
                $placeholders++;
            }
        }

        $draft = strtolower((string) ($this->legal['status'] ?? '')) === 'draft';

        if ($placeholders > 0 || $draft) {
            $level = $mode === 'production' ? self::FAIL : self::WARN;
            $results[] = $this->item(
                $level,
                'legal_placeholders',
                $mode === 'production'
                    ? 'legal placeholders/draft remain — launch blocker'
                    : 'legal placeholders/draft remain'
            );
            return;
        }

        $results[] = $this->item(self::PASS, 'legal_placeholders', 'legal identity fields configured');
    }

    /** @param list<array{status: string, code: string, message: string}> $results */
    private function checkTestPayPolicy(array &$results, string $mode): void
    {
        $allowed = self::allowsTestPayment($mode);
        if (in_array($mode, ['production', 'staging'], true) && $allowed) {
            $results[] = $this->item(self::FAIL, 'test_pay', 'test-pay must be disabled in ' . $mode);
            return;
        }

        $results[] = $this->item(
            self::PASS,
            'test_pay',
            $allowed ? 'test-pay allowed in local' : 'test-pay disabled for ' . $mode
        );
    }

    /** @return array{status: string, code: string, message: string} */
    private function item(string $status, string $code, string $message): array
    {
        return [
            'status' => $status,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace($this->root, '', $path));
    }
}
