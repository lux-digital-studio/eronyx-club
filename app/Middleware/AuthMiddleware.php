<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Authorization;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Interfaces\MiddlewareInterface;
use App\Repositories\UserRepository;

final class AuthMiddleware implements MiddlewareInterface
{
    private Auth $auth;
    private Session $session;
    private Authorization $authorization;

    public function __construct(?Auth $auth = null, ?Session $session = null, ?Authorization $authorization = null)
    {
        $this->session = $session ?? new Session();
        $this->auth = $auth ?? new Auth($this->session);

        $pdo = (new Database())->connection();
        $this->authorization = $authorization ?? new Authorization($this->auth, new UserRepository($pdo));
    }

    public function handle(Request $request, Response $response): bool
    {
        if ($this->auth->check() && $this->authorization->isActive()) {
            return true;
        }

        if ($this->auth->check()) {
            $this->session->invalidate();
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
