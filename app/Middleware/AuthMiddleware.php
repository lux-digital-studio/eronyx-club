<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Interfaces\MiddlewareInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    private Auth $auth;

    public function __construct(?Auth $auth = null)
    {
        $this->auth = $auth ?? new Auth(new Session());
    }

    public function handle(Request $request, Response $response): bool
    {
        if ($this->auth->check()) {
            return true;
        }

        $response->redirect($this->url('/login'));

        return false;
    }

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }
}
