<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public const CSP = "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; media-src 'self'; script-src 'self'; style-src 'self'";

    private bool $securityHeadersSent = false;

    public function applySecurityHeaders(?Request $request = null, bool $authenticated = false): void
    {
        if ($this->securityHeadersSent || headers_sent()) {
            return;
        }

        $request ??= new Request();
        $app = $this->appConfig();
        $https = $request->isHttps();
        $path = $request->path();
        $production = ($app['env'] ?? 'local') === 'production';

        foreach ($this->securityHeaderMap($path, $production, $https, $authenticated) as $name => $value) {
            $this->setHeader($name, $value);
        }

        $this->securityHeadersSent = true;
    }

    /**
     * @return array<string, string>
     */
    public function securityHeaderMap(
        string $path,
        bool $production,
        bool $https,
        bool $authenticated = false
    ): array {
        $security = $this->securityConfig();
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => (string) ($security['csp'] ?? self::CSP),
        ];

        if ($production && $https) {
            $headers['Strict-Transport-Security'] = (string) ($security['hsts'] ?? 'max-age=31536000; includeSubDomains');
        }

        if ($authenticated || $this->isSensitivePath($path)) {
            $headers['Cache-Control'] = 'no-store, private';
            $headers['Pragma'] = 'no-cache';
        }

        return $headers;
    }

    public function send(string $content, int $statusCode = 200): void
    {
        $this->applySecurityHeaders();
        http_response_code($this->normalizeStatus($statusCode));

        if (!headers_sent()) {
            $this->setHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        echo $content;
    }

    public function notFound(): void
    {
        $this->send('404 - Not Found', 404);
    }

    public function forbidden(): void
    {
        $this->send('403 - Forbidden', 403);
    }

    public function tooManyRequests(?int $retryAfter = null): void
    {
        if ($retryAfter !== null && $retryAfter > 0) {
            $this->setHeader('Retry-After', (string) $retryAfter);
        }

        $this->send('Demasiadas solicitudes. Inténtalo más tarde.', 429);
    }

    public function redirect(string $url, int $statusCode = 302): void
    {
        $this->applySecurityHeaders();

        if (!in_array($statusCode, [301, 302, 303, 307, 308], true)) {
            $statusCode = 302;
        }

        $safe = $this->safeRedirectUrl($url);

        if ($safe === null) {
            $this->notFound();

            return;
        }

        http_response_code($statusCode);
        $this->setHeader('Location', $safe);
        exit;
    }

    public function safeRedirectUrl(string $url): ?string
    {
        if ($url === '' || strpbrk($url, "\r\n\0") !== false) {
            return null;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        $appUrl = rtrim((string) ($this->appConfig()['url'] ?? ''), '/');

        if ($appUrl !== '' && ($url === $appUrl || str_starts_with($url, $appUrl . '/'))) {
            $remainder = substr($url, strlen($appUrl));

            if ($remainder === '' || str_starts_with($remainder, '/')) {
                return $url;
            }
        }

        return null;
    }

    public function setHeader(string $name, string $value): void
    {
        if (headers_sent() || !$this->validHeader($name, $value)) {
            return;
        }

        header($name . ': ' . $value, true);
    }

    public function isSensitivePath(string $path): bool
    {
        $prefixes = ['/account', '/moderator', '/admin', '/checkout', '/reports', '/login', '/register', '/forgot-password', '/reset-password'];

        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return $path === '/creator' || str_starts_with($path, '/creator/listings');
    }

    private function validHeader(string $name, string $value): bool
    {
        return $name !== ''
            && preg_match('/\\A[A-Za-z0-9!#$%&\'*+.^_`|~-]+\\z/', $name) === 1
            && strpbrk($value, "\r\n\0") === false;
    }

    private function normalizeStatus(int $statusCode): int
    {
        if ($statusCode < 100 || $statusCode > 599) {
            return 500;
        }

        return $statusCode;
    }

    /** @return array<string, mixed> */
    private function appConfig(): array
    {
        return require dirname(__DIR__, 2) . '/config/app.php';
    }

    /** @return array<string, mixed> */
    private function securityConfig(): array
    {
        return require dirname(__DIR__, 2) . '/config/security.php';
    }
}
