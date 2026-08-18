<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\CategoryRepository;
use App\Repositories\FavoriteRepository;
use App\Repositories\ListingRepository;
use App\Repositories\MediaRepository;
use App\Repositories\PrivateContentAccessRepository;
use App\Repositories\ProfileRepository;
use App\Services\FavoriteService;
use App\Services\MarketplaceSearchService;
use App\Services\PrivateContentAccessService;

final class MarketplaceController
{
    private Request $request;
    private Response $response;
    private Auth $auth;
    private Csrf $csrf;
    private ListingRepository $listings;
    private CategoryRepository $categories;
    private MediaRepository $media;
    private ProfileRepository $profiles;
    private MarketplaceSearchService $search;
    private FavoriteService $favorites;
    private PrivateContentAccessService $privateAccess;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $session = new Session();
        $this->auth = new Auth($session);
        $this->csrf = new Csrf($session);
        $pdo = (new Database())->connection();
        $this->listings = new ListingRepository($pdo);
        $this->categories = new CategoryRepository($pdo);
        $this->media = new MediaRepository($pdo);
        $this->profiles = new ProfileRepository($pdo);
        $this->search = new MarketplaceSearchService($this->listings);
        $this->favorites = new FavoriteService(new FavoriteRepository($pdo), $this->listings);
        $this->privateAccess = new PrivateContentAccessService(
            new PrivateContentAccessRepository($pdo),
            $this->listings,
            null,
            $pdo
        );
    }

    public function index(): string
    {
        $result = $this->search->search($this->request);
        $currentUserId = $this->auth->id();
        $favoritedListingIds = [];

        if ($currentUserId !== null && $result['items'] !== []) {
            $listingIds = array_map(static fn (array $item): int => (int) $item['id'], $result['items']);
            $favoritedListingIds = array_fill_keys(
                $this->favorites->listingIdsForUser($currentUserId, $listingIds),
                true
            );
        }

        return $this->view('marketplace/index.php', [
            'listings' => $result['items'],
            'filters' => $result['filters'],
            'total' => $result['total'],
            'perPage' => $result['perPage'],
            'currentPage' => $result['currentPage'],
            'lastPage' => $result['lastPage'],
            'query' => $result['query'],
            'categories' => $this->categories->findActive(),
            'indexUrl' => $this->url('/marketplace'),
            'creatorBaseUrl' => $this->url('/creator'),
            'mediaBaseUrl' => $this->url('/media'),
            'csrf' => $currentUserId !== null ? $this->csrf->token() : null,
            'currentUserId' => $currentUserId,
            'favoritedListingIds' => $favoritedListingIds,
            'favoriteStoreBaseUrl' => $this->url('/favorites'),
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
        $currentUserId = $this->auth->id();
        $isOwner = $currentUserId !== null && (int) $listing['owner_user_id'] === $currentUserId;
        $canFavorite = $currentUserId !== null && !$isOwner;
        $isFavorite = $canFavorite && $this->favorites->isFavorite((int) $currentUserId, $listingId);
        $creatorProfile = $this->profiles->findPublicCreatorByUserId((int) $listing['owner_user_id']);

        return $this->view('marketplace/show.php', [
            'listing' => $listing,
            'categories' => $this->listings->findCategoriesForListing($listingId),
            'mediaGroups' => $this->media->findPublicMediaForListing($listingId),
            'privateMedia' => $canAccessPrivate ? $privateMedia : [],
            'privateMediaCount' => count($privateMedia),
            'canAccessPrivateMedia' => $canAccessPrivate,
            'isOwner' => $isOwner,
            'canFavorite' => $canFavorite,
            'isFavorite' => $isFavorite,
            'csrf' => $canFavorite ? $this->csrf->token() : null,
            'favoriteStoreUrl' => $this->url('/favorites/' . $listingId),
            'favoriteDestroyUrl' => $this->url('/favorites/' . $listingId . '/delete'),
            'checkoutUrl' => $this->url('/checkout/' . $listingId),
            'canContact' => !$isOwner,
            'isAuthenticated' => $currentUserId !== null,
            'startConversationUrl' => $this->url('/messages/start/' . $listingId),
            'loginUrl' => $this->url('/login'),
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
