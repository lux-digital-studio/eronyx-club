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
use App\Repositories\ListingRepository;
use App\Services\ListingService;
use App\Validators\ListingValidator;
use Throwable;

final class CreatorListingController
{
    private Request $request;
    private Response $response;
    private Session $session;
    private Csrf $csrf;
    private Auth $auth;
    private CategoryRepository $categories;
    private ListingRepository $listings;
    private ListingService $service;
    private ListingValidator $validator;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $this->session = new Session();
        $this->csrf = new Csrf($this->session);
        $this->auth = new Auth($this->session);

        $pdo = (new Database())->connection();
        $this->categories = new CategoryRepository($pdo);
        $this->listings = new ListingRepository($pdo);
        $this->service = new ListingService($this->auth, $pdo, $this->listings, $this->categories);
        $this->validator = new ListingValidator($this->categories);
    }

    public function index(): string
    {
        return $this->view('creator/listings/index.php', [
            'listings' => $this->listings->findAllByOwner($this->ownerUserId()),
            'createUrl' => $this->url('/creator/listings/create'),
            'baseUrl' => $this->url('/creator/listings'),
        ]);
    }

    public function create(): string
    {
        return $this->form('creator/listings/create.php');
    }

    public function store(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $validation = $this->validator->validate($this->request->all());

        if (!$validation['valid']) {
            return $this->form('creator/listings/create.php', $validation['errors']);
        }

        try {
            $listingId = $this->service->create($validation['data']);
            $this->csrf->regenerate();
            $this->response->redirect($this->url('/creator/listings/' . $listingId));
        } catch (Throwable) {
            return $this->form('creator/listings/create.php', [
                'listing' => 'No se pudo crear la publicación. Inténtalo de nuevo.',
            ]);
        }

        return null;
    }

    public function show(string $id): ?string
    {
        $listingId = $this->routeId($id);

        if ($listingId === null) {
            return $this->notFound();
        }

        $listing = $this->ownedListingOrResponse($listingId);

        if (!is_array($listing)) {
            return null;
        }

        return $this->view('creator/listings/show.php', [
            'listing' => $listing,
            'categories' => $this->listings->findCategoriesForListing($listingId),
            'csrf' => $this->csrf->token(),
            'editUrl' => $this->url('/creator/listings/' . $listingId . '/edit'),
            'submitUrl' => $this->url('/creator/listings/' . $listingId . '/submit'),
            'publicUrl' => $this->url('/marketplace/' . $listing['slug']),
            'mediaUrl' => $this->url('/creator/listings/' . $listingId . '/media'),
            'indexUrl' => $this->url('/creator/listings'),
        ]);
    }

    public function edit(string $id): ?string
    {
        $listingId = $this->routeId($id);

        if ($listingId === null) {
            return $this->notFound();
        }

        $listing = $this->editableListingOrResponse($listingId);

        if (!is_array($listing)) {
            return null;
        }

        return $this->form('creator/listings/edit.php', [], $listing, $this->listings->findCategoryIdsForListing($listingId));
    }

    public function update(string $id): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $listingId = $this->routeId($id);

        if ($listingId === null) {
            return $this->notFound();
        }

        $listing = $this->editableListingOrResponse($listingId);

        if (!is_array($listing)) {
            return null;
        }

        $validation = $this->validator->validate($this->request->all());

        if (!$validation['valid']) {
            return $this->form(
                'creator/listings/edit.php',
                $validation['errors'],
                $listing,
                $this->oldCategoryIds()
            );
        }

        try {
            if (!$this->service->updateDraft($listingId, $validation['data'])) {
                return $this->forbidden();
            }

            $this->csrf->regenerate();
            $this->response->redirect($this->url('/creator/listings/' . $listingId));
        } catch (Throwable) {
            return $this->form('creator/listings/edit.php', [
                'listing' => 'No se pudo actualizar la publicación. Inténtalo de nuevo.',
            ], $listing, $this->oldCategoryIds());
        }

        return null;
    }

    public function submit(string $id): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $listingId = $this->routeId($id);

        if ($listingId === null) {
            return $this->notFound();
        }

        $listing = $this->ownedListingOrResponse($listingId);

        if (!is_array($listing)) {
            return null;
        }

        if ($listing['status'] !== 'draft') {
            return $this->forbidden();
        }

        if (!$this->service->submitForReview($listingId)) {
            return $this->forbidden();
        }

        $this->csrf->regenerate();
        $this->response->redirect($this->url('/creator/listings/' . $listingId));

        return null;
    }

    /** @param array<string, string> $errors @param array<string, mixed> $listing @param list<int>|null $selectedCategoryIds */
    private function form(string $view, array $errors = [], array $listing = [], ?array $selectedCategoryIds = null): string
    {
        return $this->view($view, [
            'csrf' => $this->csrf->token(),
            'errors' => $errors,
            'old' => $this->old($listing),
            'categories' => $this->categories->findActive(),
            'selectedCategoryIds' => $selectedCategoryIds ?? $this->oldCategoryIds($listing),
            'action' => $listing === [] ? $this->url('/creator/listings') : $this->url('/creator/listings/' . $listing['id']),
            'indexUrl' => $this->url('/creator/listings'),
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

    /** @param array<string, mixed> $fallback @return array<string, string> */
    private function old(array $fallback = []): array
    {
        $keys = ['title', 'description', 'listing_type', 'price', 'currency', 'visibility'];
        $old = [];

        foreach ($keys as $key) {
            $default = $fallback[$key] ?? ($key === 'currency' ? 'EUR' : '');
            $old[$key] = (string) $this->request->input($key, $default);
        }

        if ($old['listing_type'] === '') {
            $old['listing_type'] = 'physical_product';
        }

        if ($old['visibility'] === '') {
            $old['visibility'] = 'public';
        }

        return $old;
    }

    /** @param array<string, mixed> $fallback @return list<int> */
    private function oldCategoryIds(array $fallback = []): array
    {
        $submitted = $this->request->input('categories');

        if (is_array($submitted)) {
            return array_values(array_unique(array_map('intval', $submitted)));
        }

        if (isset($fallback['id'])) {
            return $this->listings->findCategoryIdsForListing((int) $fallback['id']);
        }

        return [];
    }

    private function routeId(string $id): ?int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return null;
        }

        return (int) $id;
    }

    /** @return array<string, mixed>|null */
    private function ownedListingOrResponse(int $listingId): ?array
    {
        if ($this->listings->findById($listingId) === null) {
            $this->response->notFound();

            return null;
        }

        $listing = $this->listings->findOwnedById($listingId, $this->ownerUserId());

        if ($listing === null) {
            $this->response->forbidden();

            return null;
        }

        return $listing;
    }

    /** @return array<string, mixed>|null */
    private function editableListingOrResponse(int $listingId): ?array
    {
        $listing = $this->ownedListingOrResponse($listingId);

        if ($listing !== null && !in_array($listing['status'], ['draft', 'rejected'], true)) {
            $this->response->forbidden();

            return null;
        }

        return $listing;
    }

    private function ownerUserId(): int
    {
        return (int) $this->auth->id();
    }

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }

    private function rejectCsrf(): ?string
    {
        $this->response->send('Solicitud no válida.', 403);

        return null;
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
