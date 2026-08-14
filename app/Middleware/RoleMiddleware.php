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

final class RoleMiddleware implements MiddlewareInterface
{
    private Authorization $authorization;

    /** @param list<string> $roles */
    public function __construct(
        private readonly array $roles,
        ?Authorization $authorization = null
    ) {
        $session = new Session();
        $pdo = (new Database())->connection();
        $this->authorization = $authorization ?? new Authorization(
            new Auth($session),
            new UserRepository($pdo)
        );
    }

    public function handle(Request $request, Response $response): bool
    {
        if ($this->authorization->hasAnyRole($this->roles)) {
            return true;
        }

        $response->forbidden();

        return false;
    }
}
