<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ListingRepository;
use App\Repositories\MediaRepository;
use App\Services\ListingMediaService;
use App\Services\MediaStorageService;
use RuntimeException;
use Throwable;

final class CreatorMediaController
{
    private Request $request;
    private Response $response;
    private Csrf $csrf;
    private ListingMediaService $service;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $session = new Session();
        $auth = new Auth($session);
        $this->csrf = new Csrf($session);

        $pdo = (new Database())->connection();
        $this->service = new ListingMediaService(
            $auth,
            $pdo,
            new ListingRepository($pdo),
            new MediaRepository($pdo),
            new MediaStorageService()
        );
    }

    public function index(string $id): ?string
    {
        $listingId = $this->routeId($id);

        if ($listingId === null) {
            return $this->notFound();
        }

        try {
            $listing = $this->service->ownedListing($listingId);
        } catch (RuntimeException $exception) {
            return $this->safeError($exception);
        }

        return $this->view('creator/listings/media/index.php', [
            'listing' => $listing,
            'mediaItems' => $this->service->mediaForListing($listingId),
            'canModify' => $this->service->canModify($listing),
            'csrf' => $this->csrf->token(),
            'action' => $this->url('/creator/listings/' . $listingId . '/media'),
            'listingUrl' => $this->url('/creator/listings/' . $listingId),
            'mediaBaseUrl' => $this->url('/media'),
            'error' => null,
        ]);
    }

    public function store(string $id): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->forbidden();
        }

        $listingId = $this->routeId($id);

        if ($listingId === null) {
            return $this->notFound();
        }

        try {
            $this->service->upload($listingId, $this->request->file('image'), (string) $this->request->input('usage_type', ''));
            $this->csrf->regenerate();
            $this->response->redirect($this->url('/creator/listings/' . $listingId . '/media'));
        } catch (RuntimeException $exception) {
            return $this->renderWithError($listingId, $exception->getMessage());
        } catch (Throwable) {
            return $this->renderWithError($listingId, 'No se pudo guardar la imagen.');
        }

        return null;
    }

    public function setCover(string $id, string $mediaId): ?string
    {
        return $this->mediaAction($id, $mediaId, 'cover');
    }

    public function destroy(string $id, string $mediaId): ?string
    {
        return $this->mediaAction($id, $mediaId, 'delete');
    }

    private function mediaAction(string $id, string $mediaId, string $action): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->forbidden();
        }

        $listingId = $this->routeId($id);
        $mediaFileId = $this->routeId($mediaId);

        if ($listingId === null || $mediaFileId === null) {
            return $this->notFound();
        }

        try {
            $ok = $action === 'cover'
                ? $this->service->setCover($listingId, $mediaFileId)
                : $this->service->delete($listingId, $mediaFileId);

            if (!$ok) {
                return $this->forbidden();
            }

            $this->csrf->regenerate();
            $this->response->redirect($this->url('/creator/listings/' . $listingId . '/media'));
        } catch (RuntimeException $exception) {
            return $this->safeError($exception);
        }

        return null;
    }

    private function renderWithError(int $listingId, string $message): ?string
    {
        try {
            $listing = $this->service->ownedListing($listingId);
        } catch (RuntimeException $exception) {
            return $this->safeError($exception);
        }

        return $this->view('creator/listings/media/index.php', [
            'listing' => $listing,
            'mediaItems' => $this->service->mediaForListing($listingId),
            'canModify' => $this->service->canModify($listing),
            'csrf' => $this->csrf->token(),
            'action' => $this->url('/creator/listings/' . $listingId . '/media'),
            'listingUrl' => $this->url('/creator/listings/' . $listingId),
            'mediaBaseUrl' => $this->url('/media'),
            'error' => $message,
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

    private function safeError(RuntimeException $exception): ?string
    {
        return match ($exception->getMessage()) {
            'not_found' => $this->notFound(),
            default => $this->forbidden(),
        };
    }

    private function routeId(string $id): ?int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return null;
        }

        return (int) $id;
    }

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }

    private function notFound(): ?string
    {
        $this->response->notFound();

        return null;
    }

    private function forbidden(): ?string
    {
        $this->response->forbidden();

        return null;
    }
}
