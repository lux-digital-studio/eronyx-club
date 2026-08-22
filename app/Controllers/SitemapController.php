<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Repositories\SeoRepository;
use App\Services\SeoService;

final class SitemapController
{
    public function xml(): ?string
    {
        $seo = new SeoService();
        $repo = new SeoRepository((new Database())->connection());
        $urls = [
            ['path' => '/', 'lastmod' => null],
            ['path' => '/marketplace', 'lastmod' => null],
            ['path' => '/legal', 'lastmod' => null],
            ['path' => '/terms', 'lastmod' => null],
            ['path' => '/privacy', 'lastmod' => null],
            ['path' => '/cookies', 'lastmod' => null],
            ['path' => '/content-policy', 'lastmod' => null],
            ['path' => '/creator-rules', 'lastmod' => null],
            ['path' => '/age-policy', 'lastmod' => null],
            ['path' => '/reporting-policy', 'lastmod' => null],
        ];
        $urls = array_merge($urls, $repo->publicListingUrls(), $repo->publicCreatorUrls());
        $xml = $this->render($seo, $urls);
        (new Response())->sendRaw($xml, 'application/xml; charset=UTF-8');

        return null;
    }

    /**
     * @param list<array{path: string, lastmod: string|null}> $urls
     */
    private function render(SeoService $seo, array $urls): string
    {
        $esc = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($urls as $entry) {
            $loc = $seo->absolute((string) $entry['path']);
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $esc($loc) . '</loc>';

            if (is_string($entry['lastmod'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $entry['lastmod']) === 1) {
                $lines[] = '    <lastmod>' . $esc($entry['lastmod']) . '</lastmod>';
            }

            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
