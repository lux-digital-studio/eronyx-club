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

        if (!$production) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }

        return $headers;
    }

    public function send(string $content, int $statusCode = 200): void
    {
        $status = $this->normalizeStatus($statusCode);

        if ($this->shouldRenderErrorPage($content, $status)) {
            $this->sendErrorPage($status);

            return;
        }

        $this->applySecurityHeaders();
        http_response_code($status);

        if (!headers_sent()) {
            $this->setHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        echo $content;
    }

    public function sendRaw(string $content, string $contentType, int $statusCode = 200): void
    {
        $status = $this->normalizeStatus($statusCode);
        $this->applySecurityHeaders();
        http_response_code($status);

        if (!headers_sent()) {
            $this->setHeader('Content-Type', $contentType);
        }

        echo $content;
    }

    public function notFound(): void
    {
        $this->sendErrorPage(404);
    }

    public function forbidden(): void
    {
        $this->sendErrorPage(403);
    }

    public function tooManyRequests(?int $retryAfter = null): void
    {
        if ($retryAfter !== null && $retryAfter > 0) {
            $this->setHeader('Retry-After', (string) $retryAfter);
        }

        $this->sendErrorPage(429);
    }

    public function serverError(): void
    {
        $this->sendErrorPage(500);
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
        $prefixes = ['/account', '/moderator', '/admin', '/checkout', '/reports', '/login', '/register', '/forgot-password', '/reset-password', '/verify-email', '/mfa'];

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

    private function shouldRenderErrorPage(string $content, int $status): bool
    {
        if (!in_array($status, [403, 404, 429, 500], true)) {
            return false;
        }

        return !str_starts_with(ltrim($content), '<');
    }

    private function sendErrorPage(int $status): void
    {
        $status = in_array($status, [403, 404, 429, 500], true) ? $status : 500;

        $this->applySecurityHeaders();
        http_response_code($status);

        if (!headers_sent()) {
            $this->setHeader('Content-Type', 'text/html; charset=UTF-8');
            $this->setHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        echo $this->renderErrorPage($status);
    }

    private function renderErrorPage(int $status): string
    {
        $homeUrl = Layout::url('/');
        $marketplaceUrl = Layout::url('/marketplace');
        $accountUrl = Layout::url('/account');
        $authenticated = false;
        $titles = [
            403 => 'Acceso denegado - ERONYX',
            404 => 'Página no encontrada - ERONYX',
            429 => 'Demasiadas solicitudes - ERONYX',
            500 => 'Error del servidor - ERONYX',
        ];
        $pageTitle = $titles[$status] ?? $titles[500];

        if ($status !== 500) {
            try {
                $authenticated = Nav::context()['authenticated'] === true;
                $inner = $this->errorView($status, $authenticated, $homeUrl, $marketplaceUrl, $accountUrl);
                ob_start();
                Layout::render($pageTitle, $inner, 'page-error', (new \App\Services\SeoService())->forError());

                return (string) ob_get_clean();
            } catch (\Throwable) {
                // Fall through to the Nav-free shell.
            }
        }

        $inner = $this->errorView($status, false, $homeUrl, $marketplaceUrl, $accountUrl);
        $cssUrl = Layout::url('/css/app.css');
        $content = $inner;

        ob_start();
        require dirname(__DIR__) . '/Views/errors/_fallback.php';

        return (string) ob_get_clean();
    }

    private function errorView(
        int $status,
        bool $authenticated,
        string $homeUrl,
        string $marketplaceUrl,
        string $accountUrl
    ): string {
        $file = dirname(__DIR__) . '/Views/errors/' . $status . '.php';

        if (!is_file($file)) {
            $file = dirname(__DIR__) . '/Views/errors/500.php';
        }

        ob_start();
        require $file;

        return (string) ob_get_clean();
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
