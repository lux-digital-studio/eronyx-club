<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AgeVerificationService;
use App\Services\CreatorApplicationService;
use App\Validators\CreatorApplicationValidator;
use RuntimeException;

final class CreatorApplicationController
{
    private Request $request;
    private Response $response;
    private Auth $auth;
    private Csrf $csrf;
    private CreatorApplicationService $service;
    private AgeVerificationService $verification;

    public function __construct()
    {
        $session = new Session();
        $this->request = new Request();
        $this->response = new Response();
        $this->auth = new Auth($session);
        $this->csrf = new Csrf($session);
        $this->service = new CreatorApplicationService();
        $this->verification = new AgeVerificationService();
    }

    public function showApply(): ?string
    {
        $userId = (int) $this->auth->id();
        $application = $this->service->findForUser($userId);

        if ($this->service->hasCreatorAccess($userId)) {
            $this->response->redirect($this->url('/creator'));

            return null;
        }

        if ($application !== null && $application['status'] === 'pending') {
            $this->response->redirect($this->url('/account/creator/status'));

            return null;
        }

        if ($application !== null && $application['status'] === 'suspended') {
            $this->response->redirect($this->url('/account/creator/status'));

            return null;
        }

        return $this->view('account/creator/apply.php', [
            'csrf' => $this->csrf->token(),
            'errors' => [],
            'action' => $this->url('/account/creator/apply'),
            'statusUrl' => $this->url('/account/creator/status'),
        ]);
    }

    public function apply(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->forbidden();
        }

        $validation = (new CreatorApplicationValidator())->validate($this->request->all());

        if (!$validation['valid']) {
            return $this->view('account/creator/apply.php', [
                'csrf' => $this->csrf->token(),
                'errors' => $validation['errors'],
                'action' => $this->url('/account/creator/apply'),
                'statusUrl' => $this->url('/account/creator/status'),
            ]);
        }

        try {
            $this->service->apply((int) $this->auth->id());
            $this->csrf->regenerate();
            $this->response->redirect($this->url('/account/creator/status'));
        } catch (RuntimeException) {
            return $this->forbidden();
        }

        return null;
    }

    public function status(): string
    {
        $userId = (int) $this->auth->id();
        $application = $this->service->findForUser($userId);

        return $this->view('account/creator/status.php', [
            'status' => $application['status'] ?? 'none',
            'verification' => $this->verification->publicSummary($userId),
            'applyUrl' => $this->url('/account/creator/apply'),
            'creatorUrl' => $this->url('/creator'),
            'accountUrl' => $this->url('/account'),
        ]);
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

    private function forbidden(): ?string
    {
        $this->response->forbidden();

        return null;
    }
}
