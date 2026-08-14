<?php

declare(strict_types=1);

namespace App\Core;

use App\Interfaces\MiddlewareInterface;

final class Router
{
    /** @var array<string, array<string, array{callback: callable|array{0: class-string, 1: string}, middleware: list<class-string<MiddlewareInterface>>}>> */
    private array $routes = [];

    public function __construct(
        private readonly Request $request,
        private readonly Response $response
    ) {
    }

    /** @param list<class-string<MiddlewareInterface>> $middleware */
    public function get(string $path, callable|array $callback, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $callback, $middleware);
    }

    /** @param list<class-string<MiddlewareInterface>> $middleware */
    public function post(string $path, callable|array $callback, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $callback, $middleware);
    }

    public function dispatch(): void
    {
        $method = $this->request->method();
        $path = $this->request->path();
        $route = $this->routes[$method][$path] ?? null;

        if ($route === null) {
            $this->response->notFound();
            return;
        }

        foreach ($route['middleware'] as $middlewareClass) {
            $middleware = new $middlewareClass();

            if (!$middleware->handle($this->request, $this->response)) {
                return;
            }
        }

        $result = $this->resolve($route['callback']);

        if (is_string($result)) {
            $this->response->send($result);
        }
    }

    /** @param list<class-string<MiddlewareInterface>> $middleware */
    private function addRoute(string $method, string $path, callable|array $callback, array $middleware): void
    {
        $this->routes[$method][$path] = [
            'callback' => $callback,
            'middleware' => $middleware,
        ];
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
