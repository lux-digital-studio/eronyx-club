<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ListingRepository;
use App\Repositories\MediaRepository;
use App\Repositories\PrivateContentAccessRepository;
use App\Repositories\ProfileRepository;
use App\Services\PrivateContentAccessService;

final class MarketplaceController
{
    private Response $response;
    private Auth $auth;
    private ListingRepository $listings;
    private MediaRepository $media;
    private ProfileRepository $profiles;
    private PrivateContentAccessService $privateAccess;

    public function __construct()
    {
        $this->response = new Response();
        $session = new Session();
        $this->auth = new Auth($session);
        $pdo = (new Database())->connection();
        $this->listings = new ListingRepository($pdo);
        $this->media = new MediaRepository($pdo);
        $this->profiles = new ProfileRepository($pdo);
        $this->privateAccess = new PrivateContentAccessService(
            new PrivateContentAccessRepository($pdo),
            $this->listings,
            null,
            $pdo
        );
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

        $listing = $this->listings->findPublishedVisibleBySlug($slug);

        if ($listing === null) {
            $this->response->notFound();

            return null;
        }

        $listingId = (int) $listing['id'];
        $privateMedia = $this->media->findPrivateMediaForListing($listingId);
        $canAccessPrivate = $this->privateAccess->canAccessListingPrivateContent($this->auth->id(), $listingId);
        $isOwner = $this->auth->id() !== null && (int) $listing['owner_user_id'] === $this->auth->id();
        $creatorProfile = $this->profiles->findPublicCreatorByUserId((int) $listing['owner_user_id']);

        return $this->view('marketplace/show.php', [
            'listing' => $listing,
            'categories' => $this->listings->findCategoriesForListing($listingId),
            'mediaGroups' => $this->media->findPublicMediaForListing($listingId),
            'privateMedia' => $canAccessPrivate ? $privateMedia : [],
            'privateMediaCount' => count($privateMedia),
            'canAccessPrivateMedia' => $canAccessPrivate,
            'isOwner' => $isOwner,
            'checkoutUrl' => $this->url('/checkout/' . $listingId),
            'creatorProfileUrl' => is_array($creatorProfile) ? $this->url('/creator/' . $creatorProfile['username']) : null,
            'creatorUsername' => is_array($creatorProfile) ? $creatorProfile['username'] : null,
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
