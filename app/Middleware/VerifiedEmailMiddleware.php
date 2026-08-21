<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Interfaces\MiddlewareInterface;
use App\Repositories\UserRepository;

final class VerifiedEmailMiddleware implements MiddlewareInterface
{
    private Auth $auth;
    private UserRepository $users;

    public function __construct(?Auth $auth = null, ?UserRepository $users = null)
    {
        $this->auth = $auth ?? new Auth(new Session());
        $this->users = $users ?? new UserRepository((new Database())->connection());
    }

    public function handle(Request $request, Response $response): bool
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            $response->redirect($this->url('/login'));

            return false;
        }

        if ($this->users->isEmailVerified($userId)) {
            return true;
        }

        $response->redirect($this->url('/account/verify-email'));

        return false;
    }

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }
}
