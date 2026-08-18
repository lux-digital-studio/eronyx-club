<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Repositories\ListingRepository;
use App\Repositories\MediaRepository;
use App\Repositories\ProfileRepository;

final class PublicCreatorController
{
    private Response $response;
    private ProfileRepository $profiles;
    private ListingRepository $listings;
    private MediaRepository $media;

    public function __construct()
    {
        $this->response = new Response();
        $pdo = (new Database())->connection();
        $this->profiles = new ProfileRepository($pdo);
        $this->listings = new ListingRepository($pdo);
        $this->media = new MediaRepository($pdo);
    }

    public function show(string $username): ?string
    {
        $username = strtolower(trim($username));

        if (preg_match('/\A[a-z0-9_-]{3,50}\z/', $username) !== 1) {
            $this->response->notFound();

            return null;
        }

        $profile = $this->profiles->findPublicCreatorByUsername($username);

        if ($profile === null) {
            $this->response->notFound();

            return null;
        }

        $listings = $this->listings->findPublishedPublicByOwner((int) $profile['user_id']);

        return $this->view('creator/public/show.php', [
            'profile' => $profile,
            'listings' => $listings,
            'coverMap' => $this->media->findCoverIdsForListings(
                array_map(static fn (array $listing): int => (int) $listing['id'], $listings)
            ),
            'mediaBaseUrl' => $this->url('/media'),
            'marketplaceUrl' => $this->url('/marketplace'),
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
