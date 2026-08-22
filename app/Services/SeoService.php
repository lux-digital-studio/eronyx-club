<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;

final class SeoService
{
    public const ROBOTS_INDEX = 'index, follow';
    public const ROBOTS_NOINDEX = 'noindex, nofollow';
    public const ROBOTS_NOINDEX_FOLLOW = 'noindex, follow';

    /** @var array<string, mixed> */
    private array $app;

    /** @var array<string, mixed> */
    private array $seo;

    /** @param array<string, mixed>|null $app @param array<string, mixed>|null $seo */
    public function __construct(?array $app = null, ?array $seo = null)
    {
        $this->app = $app ?? require dirname(__DIR__, 2) . '/config/app.php';
        $this->seo = $seo ?? require dirname(__DIR__, 2) . '/config/seo.php';
    }

    public function environment(): string
    {
        return strtolower(trim((string) ($this->app['env'] ?? 'local')));
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    public function baseUrl(): string
    {
        return rtrim((string) ($this->app['url'] ?? ''), '/');
    }

    public function absolute(string $path = '/', array $query = []): string
    {
        $normalized = $this->normalizePath($path);
        $url = $this->baseUrl() . ($normalized === '/' ? '/' : $normalized);

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{
     *   title: string,
     *   description: string,
     *   canonical: string,
     *   robots: string,
     *   ogType: string,
     *   ogTitle: string,
     *   ogDescription: string,
     *   ogUrl: string,
     *   ogImage: string|null,
     *   twitterCard: string,
     *   jsonLd: string|null
     * }
     */
    public function resolve(string $title, array $overrides = [], ?Request $request = null): array
    {
        $request ??= new Request();

        return $this->build($title, $this->normalizePath($request->path()), $this->stringQuery($request), $overrides);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function resolvePath(string $title, string $path, array $query = [], array $overrides = []): array
    {
        return $this->build($title, $this->normalizePath($path), $query, $overrides);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function build(string $title, string $path, array $query, array $overrides): array
    {
        $robots = isset($overrides['robots']) && is_string($overrides['robots']) && $overrides['robots'] !== ''
            ? $overrides['robots']
            : $this->robotsFor($path, $query);
        $omitCanonical = array_key_exists('canonical', $overrides)
            && ($overrides['canonical'] === null || $overrides['canonical'] === false || $overrides['canonical'] === '');
        $canonical = $omitCanonical
            ? ''
            : (
                isset($overrides['canonical']) && is_string($overrides['canonical']) && $overrides['canonical'] !== ''
                    ? $overrides['canonical']
                    : $this->canonicalFor($path, $query)
            );
        $description = $this->clip(
            $this->stringOverride($overrides, 'description', (string) ($this->seo['default_description'] ?? '')),
            (int) ($this->seo['description_max'] ?? 160)
        );
        $pageTitle = $this->clip(
            $this->stringOverride($overrides, 'title', $title !== '' ? $title : (string) ($this->seo['default_title'] ?? 'ERONYX')),
            (int) ($this->seo['title_max'] ?? 60)
        );
        $ogImage = $this->publicImageUrl($overrides['ogImage'] ?? ($this->seo['default_social_image'] ?? ''));

        if (array_key_exists('jsonLd', $overrides)) {
            $jsonLd = $overrides['jsonLd'];
        } else {
            $jsonLd = $this->defaultJsonLd($path, $pageTitle, $description, $canonical);
        }

        return [
            'title' => $pageTitle !== '' ? $pageTitle : 'ERONYX',
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $robots,
            'ogType' => $this->stringOverride($overrides, 'ogType', (string) ($this->seo['og_type'] ?? 'website')),
            'ogTitle' => $this->stringOverride($overrides, 'ogTitle', $pageTitle),
            'ogDescription' => $this->stringOverride($overrides, 'ogDescription', $description),
            'ogUrl' => $this->stringOverride($overrides, 'ogUrl', $canonical),
            'ogImage' => $ogImage,
            'twitterCard' => $ogImage !== null
                ? (string) ($this->seo['twitter_card'] ?? 'summary_large_image')
                : 'summary',
            'jsonLd' => is_string($jsonLd) && $jsonLd !== '' ? $jsonLd : $this->encodeJsonLd(is_array($jsonLd) ? $jsonLd : null),
        ];
    }

    /**
     * @param array<string, mixed> $listing
     * @return array<string, mixed>
     */
    public function forListing(array $listing, ?string $coverUrl = null): array
    {
        $title = trim((string) ($listing['title'] ?? ''));
        $title = $title !== '' ? $title . ' | ERONYX' : (string) ($this->seo['default_title'] ?? 'ERONYX');
        $description = trim((string) ($listing['description'] ?? ''));
        $slug = (string) ($listing['slug'] ?? '');
        $visibility = (string) ($listing['visibility'] ?? '');
        $public = $visibility === 'public';
        $canonical = $this->absolute('/marketplace/' . ltrim($slug, '/'));
        $image = $this->publicImageUrl($coverUrl);
        $graph = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => trim((string) ($listing['title'] ?? '')) !== '' ? (string) $listing['title'] : 'Publicación ERONYX',
            'description' => $description !== '' ? $description : (string) ($this->seo['default_description'] ?? ''),
            'url' => $canonical,
            'offers' => [
                '@type' => 'Offer',
                'price' => (string) ($listing['price'] ?? ''),
                'priceCurrency' => strtoupper((string) ($listing['currency'] ?? 'EUR')),
                'url' => $canonical,
            ],
        ];

        if ($image !== null) {
            $graph['image'] = $image;
        }

        return [
            'title' => $title,
            'description' => $description !== '' ? $description : (string) ($this->seo['default_description'] ?? ''),
            'canonical' => $canonical,
            'robots' => $public ? $this->publicRobots() : self::ROBOTS_NOINDEX,
            'ogType' => 'product',
            'ogImage' => $image,
            'jsonLd' => $graph,
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function forCreator(array $profile, ?string $avatarUrl = null): array
    {
        $name = trim((string) ($profile['display_name'] ?? ''));
        $username = (string) ($profile['username'] ?? '');
        $bio = trim((string) ($profile['bio'] ?? ''));
        $canonical = $this->absolute('/creator/' . rawurlencode($username));
        $image = $this->publicImageUrl($avatarUrl);
        $graph = [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $name !== '' ? $name : $username,
            'url' => $canonical,
        ];

        if ($image !== null) {
            $graph['image'] = $image;
        }

        return [
            'title' => ($name !== '' ? $name : $username) . ' | ERONYX',
            'description' => $bio !== '' ? $bio : (string) ($this->seo['default_description'] ?? ''),
            'canonical' => $canonical,
            'robots' => $this->publicRobots(),
            'ogType' => 'profile',
            'ogImage' => $image,
            'jsonLd' => $graph,
        ];
    }

    /** @return array<string, mixed> */
    public function forError(): array
    {
        return [
            'robots' => self::ROBOTS_NOINDEX,
            // 404/403/429/500: noindex, no canonical-to-home, and do not echo the requested path.
            'canonical' => false,
            'jsonLd' => null,
        ];
    }

    /**
     * @param array<string, string> $query
     */
    public function robotsFor(string $path, array $query = []): string
    {
        if (!$this->isProduction()) {
            return self::ROBOTS_NOINDEX;
        }

        $path = $this->normalizePath($path);

        if ($this->isPrivatePath($path)) {
            return self::ROBOTS_NOINDEX;
        }

        if ($this->isMarketplaceIndex($path) && $this->hasFilterQuery($query)) {
            return self::ROBOTS_NOINDEX_FOLLOW;
        }

        if ($this->isIndexablePath($path)) {
            return self::ROBOTS_INDEX;
        }

        return self::ROBOTS_NOINDEX;
    }

    /**
     * @param array<string, string> $query
     */
    public function canonicalFor(string $path, array $query = []): string
    {
        $path = $this->normalizePath($path);
        $keep = [];

        if ($this->isMarketplaceIndex($path) && !$this->hasFilterQuery($query)) {
            $page = (int) ($query['page'] ?? 1);

            if ($page > 1) {
                $keep['page'] = (string) $page;
            }
        }

        return $this->absolute($path, $keep);
    }

    public function encodeJsonLd(mixed $data): ?string
    {
        if (!is_array($data) || $data === []) {
            return null;
        }

        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);

        return is_string($json) && $json !== '' && $json !== 'null' ? $json : null;
    }

    public function publicImageUrl(mixed $candidate): ?string
    {
        if (!is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        $value = trim($candidate);
        $base = $this->baseUrl();

        if ($value[0] === '/') {
            $value = $this->absolute($value);
        }

        if ($base === '' || !str_starts_with($value, $base . '/')) {
            return null;
        }

        $path = substr($value, strlen($base));

        if (str_contains($path, 'storage') || str_contains($path, 'data:') || str_contains($path, '..')) {
            return null;
        }

        if (preg_match('/\A\/media\/[1-9][0-9]*\z/', $path) === 1) {
            return $value;
        }

        $default = trim((string) ($this->seo['default_social_image'] ?? ''));

        if ($default !== '' && $value === $this->absolute($default)) {
            return $value;
        }

        return null;
    }

    /** @param array<string, string> $query */
    public function hasFilterQuery(array $query): bool
    {
        foreach (['q', 'category', 'type', 'min_price', 'max_price', 'creator'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                return true;
            }
        }

        return isset($query['sort']) && $query['sort'] !== '' && $query['sort'] !== 'newest';
    }

    public function isPrivatePath(string $path): bool
    {
        $path = $this->normalizePath($path);
        $prefixes = [
            '/login', '/register', '/forgot-password', '/reset-password', '/verify-email',
            '/mfa', '/account', '/checkout', '/admin', '/moderator', '/reports',
            '/favorites', '/messages',
        ];

        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return $path === '/creator' || str_starts_with($path, '/creator/listings');
    }

    public function isIndexablePath(string $path): bool
    {
        $path = $this->normalizePath($path);
        $exact = [
            '/', '/marketplace', '/legal', '/terms', '/privacy', '/cookies',
            '/content-policy', '/creator-rules', '/age-policy', '/reporting-policy',
        ];

        if (in_array($path, $exact, true)) {
            return true;
        }

        if (preg_match('/\A\/marketplace\/[a-z0-9]+(?:-[a-z0-9]+)*\z/', $path) === 1) {
            return true;
        }

        return preg_match('/\A\/creator\/[a-z0-9_-]{3,50}\z/', $path) === 1
            && !str_starts_with($path, '/creator/listings');
    }

    public function robotsTxt(): string
    {
        $lines = ['User-agent: *'];

        if (!$this->isProduction()) {
            $lines[] = 'Disallow: /';
            $lines[] = '';

            return implode("\n", $lines);
        }

        $lines[] = 'Allow: /';
        foreach ([
            '/account/', '/admin/', '/moderator/', '/checkout/', '/mfa/',
            '/login', '/register', '/forgot-password', '/reset-password', '/verify-email',
            '/creator/listings',
        ] as $disallow) {
            $lines[] = 'Disallow: ' . $disallow;
        }
        $lines[] = 'Sitemap: ' . $this->absolute('/sitemap.xml');
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function publicRobots(): string
    {
        return $this->isProduction() ? self::ROBOTS_INDEX : self::ROBOTS_NOINDEX;
    }

    private function isMarketplaceIndex(string $path): bool
    {
        return $this->normalizePath($path) === '/marketplace';
    }

    private function defaultJsonLd(string $path, string $title, string $description, string $canonical): ?string
    {
        $path = $this->normalizePath($path);

        if ($path === '/') {
            return $this->encodeJsonLd([
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => (string) ($this->seo['site_name'] ?? 'ERONYX'),
                'url' => $this->absolute('/'),
                'description' => $description,
            ]);
        }

        $legal = [
            '/legal', '/terms', '/privacy', '/cookies',
            '/content-policy', '/creator-rules', '/age-policy', '/reporting-policy',
        ];

        if (in_array($path, $legal, true) || $path === '/marketplace') {
            return $this->encodeJsonLd([
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => $title,
                'description' => $description,
                'url' => $canonical,
            ]);
        }

        return null;
    }

    /** @return array<string, string> */
    private function stringQuery(Request $request): array
    {
        $out = [];

        foreach ($request->queryParameters() as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $overrides */
    private function stringOverride(array $overrides, string $key, string $fallback): string
    {
        $value = $overrides[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return $fallback;
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $path = parse_url($path, PHP_URL_PATH) ?: '/';

        if ($path !== '/' ) {
            $path = rtrim($path, '/') ?: '/';
        }

        return $path === '' ? '/' : $path;
    }

    private function clip(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        if ($value === '' || $max < 1) {
            return $value;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value) <= $max) {
                return $value;
            }

            return rtrim(mb_substr($value, 0, $max - 1)) . '…';
        }

        if (strlen($value) <= $max) {
            return $value;
        }

        return rtrim(substr($value, 0, $max - 1)) . '…';
    }
}
