<?php

declare(strict_types=1);

namespace App\Core;

final class Application
{
    private Request $request;
    private Response $response;
    private Router $router;

    public function __construct(
        ?Request $request = null,
        ?Response $response = null,
        ?Router $router = null
    ) {
        $this->request = $request ?? new Request();
        $this->response = $response ?? new Response();
        $this->router = $router ?? new Router($this->request, $this->response);

        $this->loadRoutes();
    }

    public function run(): void
    {
        $this->router->dispatch();
    }

    private function loadRoutes(): void
    {
        $routes = require dirname(__DIR__, 2) . '/routes/web.php';

        if (is_callable($routes)) {
            $routes($this->router);
        }
    }
}