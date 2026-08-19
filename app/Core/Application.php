<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

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
        $this->registerErrorHandling();

        $session = new Session();
        $session->start();
        $authenticated = (new Auth($session))->check();
        $this->response->applySecurityHeaders($this->request, $authenticated);

        try {
            $this->router->dispatch();
        } catch (Throwable $exception) {
            $this->handleException($exception);
        }
    }

    private function registerErrorHandling(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');

        set_exception_handler(function (Throwable $exception): void {
            $this->handleException($exception);
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    private function handleException(Throwable $exception): void
    {
        Logger::error('Unhandled exception', [
            'type' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        if (!headers_sent()) {
            $this->response->send('Ha ocurrido un error. Inténtalo de nuevo más tarde.', 500);
        }
    }

    private function loadRoutes(): void
    {
        $routes = require dirname(__DIR__, 2) . '/routes/web.php';

        if (is_callable($routes)) {
            $routes($this->router);
        }
    }
}
