<?php

declare(strict_types=1);

namespace App\Core;

use App\Interfaces\MiddlewareInterface;

final class Router
{
    /** @var array<string, array<string, array{callback: callable|array{0: class-string, 1: string}, middleware: list<class-string<MiddlewareInterface>|array{0: class-string<MiddlewareInterface>, 1: array<int, mixed>}>}>> */
    private array $routes = [];

    public function __construct(
        private readonly Request $request,
        private readonly Response $response
    ) {
    }

    /** @param list<class-string<MiddlewareInterface>|array{0: class-string<MiddlewareInterface>, 1: array<int, mixed>}> $middleware */
    public function get(string $path, callable|array $callback, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $callback, $middleware);
    }

    /** @param list<class-string<MiddlewareInterface>|array{0: class-string<MiddlewareInterface>, 1: array<int, mixed>}> $middleware */
    public function post(string $path, callable|array $callback, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $callback, $middleware);
    }

    public function dispatch(): void
    {
        $method = $this->request->method();
        $path = $this->request->path();
        $match = $this->match($method, $path);

        if ($match === null) {
            $this->response->notFound();
            return;
        }

        $route = $match['route'];

        foreach ($route['middleware'] as $middlewareDefinition) {
            $middleware = $this->makeMiddleware($middlewareDefinition);

            if (!$middleware->handle($this->request, $this->response)) {
                return;
            }
        }

        $result = $this->resolve($route['callback'], $match['params']);

        if (is_string($result)) {
            $this->response->send($result);
        }
    }

    /** @param list<class-string<MiddlewareInterface>|array{0: class-string<MiddlewareInterface>, 1: array<int, mixed>}> $middleware */
    private function addRoute(string $method, string $path, callable|array $callback, array $middleware): void
    {
        $this->routes[$method][$path] = [
            'callback' => $callback,
            'middleware' => $middleware,
        ];
    }

    /**
     * @return array{route: array{callback: callable|array{0: class-string, 1: string}, middleware: list<class-string<MiddlewareInterface>|array{0: class-string<MiddlewareInterface>, 1: array<int, mixed>}>}, params: list<string>}|null
     */
    private function match(string $method, string $path): ?array
    {
        $routes = $this->routes[$method] ?? [];
        $exact = $routes[$path] ?? null;

        if ($exact !== null) {
            return ['route' => $exact, 'params' => []];
        }

        foreach ($routes as $pattern => $route) {
            if (!str_contains($pattern, '{')) {
                continue;
            }

            $params = $this->matchDynamicPattern($pattern, $path);

            if ($params !== null) {
                return ['route' => $route, 'params' => $params];
            }
        }

        return null;
    }

    /** @return list<string>|null */
    private function matchDynamicPattern(string $pattern, string $path): ?array
    {
        $patternSegments = explode('/', trim($pattern, '/'));
        $pathSegments = explode('/', trim($path, '/'));

        if (count($patternSegments) !== count($pathSegments)) {
            return null;
        }

        $params = [];

        foreach ($patternSegments as $index => $segment) {
            $pathSegment = $pathSegments[$index] ?? '';

            if (preg_match('/^\{[a-zA-Z_][a-zA-Z0-9_]*\}$/', $segment) === 1) {
                if ($pathSegment === '') {
                    return null;
                }

                $params[] = $pathSegment;
                continue;
            }

            if ($segment !== $pathSegment) {
                return null;
            }
        }

        return $params;
    }

    /** @param list<string> $params */
    private function resolve(callable|array $callback, array $params = []): mixed
    {
        if (is_array($callback) && is_string($callback[0] ?? null) && is_string($callback[1] ?? null)) {
            $controller = new $callback[0]();

            return $controller->{$callback[1]}(...$params);
        }

        return $callback(...$params);
    }

    /** @param class-string<MiddlewareInterface>|array{0: class-string<MiddlewareInterface>, 1: array<int, mixed>} $definition */
    private function makeMiddleware(string|array $definition): MiddlewareInterface
    {
        if (is_array($definition)) {
            return new $definition[0](...$definition[1]);
        }

        return new $definition();
    }
}
