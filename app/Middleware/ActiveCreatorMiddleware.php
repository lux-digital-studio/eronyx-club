<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Interfaces\MiddlewareInterface;
use App\Repositories\CreatorApplicationRepository;

final class ActiveCreatorMiddleware implements MiddlewareInterface
{
    private Auth $auth;
    private CreatorApplicationRepository $creatorProfiles;

    public function __construct(?Auth $auth = null, ?CreatorApplicationRepository $creatorProfiles = null)
    {
        $this->auth = $auth ?? new Auth(new Session());
        $pdo = (new Database())->connection();
        $this->creatorProfiles = $creatorProfiles ?? new CreatorApplicationRepository($pdo);
    }

    public function handle(Request $request, Response $response): bool
    {
        $userId = $this->auth->id();

        if ($userId === null || !$this->creatorProfiles->hasActiveCreatorProfile($userId)) {
            $response->forbidden();

            return false;
        }

        return true;
    }
}
