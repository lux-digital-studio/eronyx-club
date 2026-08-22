<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\OrderItemRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Services\CommerceService;
use RuntimeException;
use Throwable;

final class OrderController
{
    private Request $request;
    private Response $response;
    private Auth $auth;
    private Csrf $csrf;
    private OrderRepository $orders;
    private OrderItemRepository $items;
    private PaymentRepository $payments;
    private CommerceService $commerce;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $session = new Session();
        $this->auth = new Auth($session);
        $this->csrf = new Csrf($session);
        $pdo = (new Database())->connection();
        $this->orders = new OrderRepository($pdo);
        $this->items = new OrderItemRepository($pdo);
        $this->payments = new PaymentRepository($pdo);
        $this->commerce = new CommerceService($pdo, $this->orders, $this->items, $this->payments);
    }

    public function index(): string
    {
        return $this->view('account/orders/index.php', [
            'orders' => $this->orders->findAllByBuyer($this->userId()),
            'baseUrl' => $this->url('/account/orders'),
            'accountUrl' => $this->url('/account'),
        ]);
    }

    public function show(string $id): ?string
    {
        $orderId = $this->routeId($id);

        if ($orderId === null) {
            return $this->notFound();
        }

        $order = $this->ownedOrderOrResponse($orderId);

        if ($order === null) {
            return null;
        }

        return $this->view('account/orders/show.php', [
            'order' => $order,
            'items' => $this->items->findByOrder($orderId),
            'payment' => $this->payments->findByOrder($orderId),
            'csrf' => $this->csrf->token(),
            'isLocal' => $this->isLocal(),
            'payUrl' => $this->url('/account/orders/' . $orderId . '/test-pay'),
            'indexUrl' => $this->url('/account/orders'),
        ]);
    }

    public function testPay(string $id): ?string
    {
        if (!$this->isLocal()) {
            return $this->notFound();
        }

        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            $this->response->send('Solicitud no válida.', 403);

            return null;
        }

        $orderId = $this->routeId($id);

        if ($orderId === null) {
            return $this->notFound();
        }

        try {
            if (!$this->commerce->confirmTestPayment($orderId, $this->userId())) {
                return $this->forbidden();
            }

            $this->csrf->regenerate();
            $this->response->redirect($this->url('/account/orders/' . $orderId));

            return null;
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'forbidden') {
                return $this->forbidden();
            }

            return $this->notFound();
        } catch (Throwable) {
            $this->response->send('No se pudo confirmar el pago.', 500);

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

    /** @return array<string, mixed>|null */
    private function ownedOrderOrResponse(int $orderId): ?array
    {
        if ($this->orders->findById($orderId) === null) {
            $this->response->notFound();

            return null;
        }

        $order = $this->orders->findOwnedById($orderId, $this->userId());

        if ($order === null) {
            $this->response->forbidden();

            return null;
        }

        return $order;
    }

    private function userId(): int
    {
        return (int) $this->auth->id();
    }

    private function isLocal(): bool
    {
        return \App\Core\EnvironmentValidator::allowsTestPayment(\App\Core\EnvironmentValidator::currentEnv());
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

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }
}
