<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\CreatorApplicationService;
use RuntimeException;

final class ModeratorCreatorApplicationController
{
    private Request $request;
    private Response $response;
    private Auth $auth;
    private Csrf $csrf;
    private CreatorApplicationService $service;

    public function __construct()
    {
        $session = new Session();
        $this->request = new Request();
        $this->response = new Response();
        $this->auth = new Auth($session);
        $this->csrf = new Csrf($session);
        $this->service = new CreatorApplicationService();
    }

    public function index(): string
    {
        return $this->view('moderator/creator-applications/index.php', [
            'applications' => $this->service->pendingApplications(),
            'baseUrl' => $this->url('/moderator/creator-applications'),
        ]);
    }

    public function show(string $id): ?string
    {
        $applicationId = $this->routeId($id);

        if ($applicationId === null) {
            return $this->notFound();
        }

        $application = $this->service->pendingApplication($applicationId);

        if ($application === null) {
            return $this->notFound();
        }

        return $this->view('moderator/creator-applications/show.php', [
            'application' => $application,
            'csrf' => $this->csrf->token(),
            'approveUrl' => $this->url('/moderator/creator-applications/' . $applicationId . '/approve'),
            'rejectUrl' => $this->url('/moderator/creator-applications/' . $applicationId . '/reject'),
            'indexUrl' => $this->url('/moderator/creator-applications'),
        ]);
    }

    public function approve(string $id): ?string
    {
        return $this->transition($id, true);
    }

    public function reject(string $id): ?string
    {
        return $this->transition($id, false);
    }

    private function transition(string $id, bool $approve): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->forbidden();
        }

        $applicationId = $this->routeId($id);

        if ($applicationId === null) {
            return $this->notFound();
        }

        try {
            $ok = $approve
                ? $this->service->approve($applicationId, (int) $this->auth->id())
                : $this->service->reject($applicationId, (int) $this->auth->id());
        } catch (RuntimeException) {
            return $this->forbidden();
        }

        if (!$ok) {
            return $this->forbidden();
        }

        $this->csrf->regenerate();
        $this->response->redirect($this->url('/moderator/creator-applications'));

        return null;
    }

    private function routeId(string $id): ?int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return null;
        }

        return (int) $id;
    }

    /** @param array<string, mixed> $data */
    private function view(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require dirname(__DIR__) . '/Views/' . $view;

        return (string) ob_get_clean();
    }

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }

    private function notFound(): ?string
    {
        $this->response->notFound();

        return null;
    }

    private function forbidden(): ?string
    {
        $this->response->forbidden();

        return null;
    }
}
