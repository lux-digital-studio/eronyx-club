<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ListingRepository;
use App\Services\ListingService;

final class ModeratorListingController
{
    private Request $request;
    private Response $response;
    private Csrf $csrf;
    private ListingRepository $listings;
    private ListingService $service;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $session = new Session();
        $this->csrf = new Csrf($session);

        $pdo = (new Database())->connection();
        $this->listings = new ListingRepository($pdo);
        $this->service = new ListingService(new \App\Core\Auth($session), $pdo, $this->listings);
    }

    public function index(): string
    {
        return $this->view('moderator/listings/index.php', [
            'listings' => $this->listings->findPendingReview(),
            'baseUrl' => $this->url('/moderator/listings'),
        ]);
    }

    public function show(string $id): ?string
    {
        $listingId = $this->routeId($id);

        if ($listingId === null) {
            return $this->notFound();
        }

        $listing = $this->listings->findPendingReviewById($listingId);

        if ($listing === null) {
            return $this->notFound();
        }

        return $this->view('moderator/listings/show.php', [
            'listing' => $listing,
            'categories' => $this->listings->findCategoriesForListing($listingId),
            'csrf' => $this->csrf->token(),
            'approveUrl' => $this->url('/moderator/listings/' . $listingId . '/approve'),
            'rejectUrl' => $this->url('/moderator/listings/' . $listingId . '/reject'),
            'indexUrl' => $this->url('/moderator/listings'),
        ]);
    }

    public function approve(string $id): ?string
    {
        return $this->transition($id, 'approve');
    }

    public function reject(string $id): ?string
    {
        return $this->transition($id, 'reject');
    }

    private function transition(string $id, string $action): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            $this->response->send('Solicitud no válida.', 403);

            return null;
        }

        $listingId = $this->routeId($id);

        if ($listingId === null) {
            return $this->notFound();
        }

        $ok = $action === 'approve'
            ? $this->service->approve($listingId)
            : $this->service->reject($listingId);

        if (!$ok) {
            return $this->notFound();
        }

        $this->csrf->regenerate();
        $this->response->redirect($this->url('/moderator/listings'));

        return null;
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
}
