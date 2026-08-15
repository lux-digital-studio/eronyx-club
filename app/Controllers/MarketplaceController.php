<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Repositories\ListingRepository;
use App\Repositories\MediaRepository;

final class MarketplaceController
{
    private Response $response;
    private ListingRepository $listings;
    private MediaRepository $media;

    public function __construct()
    {
        $this->response = new Response();
        $pdo = (new Database())->connection();
        $this->listings = new ListingRepository($pdo);
        $this->media = new MediaRepository($pdo);
    }

    public function index(): string
    {
        $listings = $this->listings->findPublishedPublic();
        $categoryMap = $this->listings->findCategoriesForListings(
            array_map(static fn (array $listing): int => (int) $listing['id'], $listings)
        );

        return $this->view('marketplace/index.php', [
            'listings' => $listings,
            'categoryMap' => $categoryMap,
            'coverMap' => $this->media->findCoverIdsForListings(
                array_map(static fn (array $listing): int => (int) $listing['id'], $listings)
            ),
            'baseUrl' => $this->url('/marketplace'),
            'mediaBaseUrl' => $this->url('/media'),
        ]);
    }

    public function show(string $slug): ?string
    {
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1) {
            $this->response->notFound();

            return null;
        }

        $listing = $this->listings->findPublishedPublicBySlug($slug);

        if ($listing === null) {
            $this->response->notFound();

            return null;
        }

        return $this->view('marketplace/show.php', [
            'listing' => $listing,
            'categories' => $this->listings->findCategoriesForListing((int) $listing['id']),
            'mediaGroups' => $this->media->findPublicMediaForListing((int) $listing['id']),
            'mediaBaseUrl' => $this->url('/media'),
            'indexUrl' => $this->url('/marketplace'),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function view(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require dirname(__DIR__) . '/Views/' . $view;

        return (string) ob_get_clean();
    }

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }
}
