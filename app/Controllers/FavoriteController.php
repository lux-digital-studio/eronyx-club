<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\FavoriteRepository;
use App\Repositories\ListingRepository;
use App\Services\FavoriteService;
use RuntimeException;
use Throwable;

final class FavoriteController
{
    private Request $request;
    private Response $response;
    private Auth $auth;
    private Csrf $csrf;
    private FavoriteService $favorites;
    private ListingRepository $listings;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $session = new Session();
        $this->auth = new Auth($session);
        $this->csrf = new Csrf($session);
        $pdo = (new Database())->connection();
        $this->listings = new ListingRepository($pdo);
        $this->favorites = new FavoriteService(new FavoriteRepository($pdo), $this->listings);
    }

    public function index(): string
    {
        $result = $this->favorites->listFavorites($this->userId(), $this->request->query('page'));

        return $this->view('account/favorites/index.php', [
            'listings' => $result['items'],
            'total' => $result['total'],
            'perPage' => $result['perPage'],
            'currentPage' => $result['currentPage'],
            'lastPage' => $result['lastPage'],
            'csrf' => $this->csrf->token(),
            'accountUrl' => $this->url('/account'),
            'indexUrl' => $this->url('/account/favorites'),
            'marketplaceUrl' => $this->url('/marketplace'),
            'creatorBaseUrl' => $this->url('/creator'),
            'mediaBaseUrl' => $this->url('/media'),
            'favoriteDestroyBaseUrl' => $this->url('/favorites'),
        ]);
    }

    public function store(string $listingId): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            $this->response->send('Solicitud no válida.', 403);

            return null;
        }

        $id = $this->routeId($listingId);

        if ($id === null) {
            return $this->notFound();
        }

        try {
            $this->favorites->addFavorite($this->userId(), $id);
            $this->csrf->regenerate();
            $this->redirectAfter($id);

            return null;
        } catch (RuntimeException $exception) {
            return $this->mappedRuntimeResponse($exception);
        } catch (Throwable) {
            $this->response->send('No se pudo guardar el favorito.', 500);

            return null;
        }
    }

    public function destroy(string $listingId): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            $this->response->send('Solicitud no válida.', 403);

            return null;
        }

        $id = $this->routeId($listingId);

        if ($id === null) {
            return $this->notFound();
        }

        try {
            $this->favorites->removeFavorite($this->userId(), $id);
            $this->csrf->regenerate();
            $this->redirectAfter($id);

            return null;
        } catch (Throwable) {
            $this->response->send('No se pudo quitar el favorito.', 500);

            return null;
        }
    }

    /** @param array<string, mixed> $data */
    private function view(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require dirname(__DIR__) . '/Views/' . $view;

        return (string) ob_get_clean();
    }

    private function routeId(string $id): ?int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return null;
        }

        return (int) $id;
    }

    private function userId(): int
    {
        return (int) $this->auth->id();
    }

    private function redirectAfter(int $listingId): void
    {
        $source = $this->request->input('source');
        $source = is_string($source) ? $source : '';

        if ($source === 'favorites') {
            $this->response->redirect($this->url('/account/favorites'));

            return;
        }

        if ($source === 'detail') {
            $listing = $this->listings->findById($listingId);
            $slug = is_array($listing) ? (string) ($listing['slug'] ?? '') : '';

            if ($slug !== '' && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) === 1) {
                $this->response->redirect($this->url('/marketplace/' . $slug));

                return;
            }
        }

        $this->response->redirect($this->url('/marketplace'));
    }

    private function mappedRuntimeResponse(RuntimeException $exception): ?string
    {
        if ($exception->getMessage() === 'forbidden') {
            $this->response->forbidden();

            return null;
        }

        return $this->notFound();
    }

    private function notFound(): ?string
    {
        $this->response->notFound();

        return null;
    }

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }
}
