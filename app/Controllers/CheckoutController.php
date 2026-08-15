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
use App\Services\CommerceService;
use RuntimeException;
use Throwable;

final class CheckoutController
{
    private Request $request;
    private Response $response;
    private Auth $auth;
    private Csrf $csrf;
    private CommerceService $commerce;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $session = new Session();
        $this->auth = new Auth($session);
        $this->csrf = new Csrf($session);
        $pdo = (new Database())->connection();
        $this->commerce = new CommerceService($pdo, null, null, null, new ListingRepository($pdo));
    }

    public function show(string $listingId): ?string
    {
        $id = $this->routeId($listingId);

        if ($id === null) {
            return $this->notFound();
        }

        try {
            return $this->view('checkout/show.php', [
                'listing' => $this->commerce->checkoutPreview($this->userId(), $id),
                'csrf' => $this->csrf->token(),
                'action' => $this->url('/checkout/' . $id),
                'marketplaceUrl' => $this->url('/marketplace'),
            ]);
        } catch (RuntimeException $exception) {
            return $this->mappedRuntimeResponse($exception);
        }
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
            $checkout = $this->commerce->createCheckout($this->userId(), $id);
            $this->csrf->regenerate();
            $this->response->redirect($this->url('/account/orders/' . $checkout['order_id']));

            return null;
        } catch (RuntimeException $exception) {
            return $this->mappedRuntimeResponse($exception);
        } catch (Throwable) {
            $this->response->send('No se pudo crear el pedido.', 500);

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

    private function mappedRuntimeResponse(RuntimeException $exception): ?string
    {
        if ($exception->getMessage() === 'forbidden') {
            $this->response->forbidden();

            return null;
        }

        $this->response->notFound();

        return null;
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
