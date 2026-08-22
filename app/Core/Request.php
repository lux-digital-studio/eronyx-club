<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string, mixed>|null */
    private ?array $parsedInput = null;

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }

        $trusted = $this->trustedProxies();

        if ($trusted === [] || !in_array($remote, $trusted, true)) {
            return $remote;
        }

        $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));

        if ($forwarded === '') {
            return $remote;
        }

        $candidate = trim(explode(',', $forwarded)[0]);

        return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : $remote;
    }

    public function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $trusted = $this->trustedProxies();

        if ($trusted === [] || !in_array($remote, $trusted, true)) {
            return false;
        }

        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

        return $proto === 'https';
    }

    public function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

        if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        return $path === '' ? '/' : $path;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->parsedInput()[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function queryParameters(): array
    {
        return $_GET;
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $file = $_FILES[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->parsedInput();
    }

    /** @return array<string, mixed> */
    private function parsedInput(): array
    {
        if ($this->parsedInput !== null) {
            return $this->parsedInput;
        }

        $this->parsedInput = $_POST;

        if ($this->parsedInput === [] && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded')) {
            parse_str((string) file_get_contents('php://input'), $this->parsedInput);
        }

        return $this->parsedInput;
    }

    /** @return list<string> */
    private function trustedProxies(): array
    {
        $config = require dirname(__DIR__, 2) . '/config/security.php';
        $proxies = $config['trusted_proxies'] ?? [];

        if (!is_array($proxies)) {
            return [];
        }

        $valid = [];

        foreach ($proxies as $proxy) {
            if (is_string($proxy) && filter_var($proxy, FILTER_VALIDATE_IP) !== false) {
                $valid[] = $proxy;
            }
        }

        return $valid;
    }
}
