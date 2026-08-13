<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string, array<string, callable|array{0: class-string, 1: string}>> */
    private array $routes = [];

    public function __construct(
        private readonly Request $request,
        private readonly Response $response
    ) {
    }

    public function get(string $path, callable|array $callback): void
    {
        $this->addRoute('GET', $path, $callback);
    }

    public function post(string $path, callable|array $callback): void
    {
        $this->addRoute('POST', $path, $callback);
    }

    public function dispatch(): void
    {
        $method = $this->request->method();
        $path = $this->request->path();
        $callback = $this->routes[$method][$path] ?? null;

        if ($callback === null) {
            $this->response->notFound();
            return;
        }

        $result = $this->resolve($callback);

        if (is_string($result)) {
            $this->response->send($result);
        }
    }

    private function addRoute(string $method, string $path, callable|array $callback): void
    {
        $this->routes[$method][$path] = $callback;
    }

    private function resolve(callable|array $callback): mixed
    {
        if (is_array($callback) && is_string($callback[0] ?? null) && is_string($callback[1] ?? null)) {
            $controller = new $callback[0]();

            return $controller->{$callback[1]}();
        }

        return $callback();
    }
}