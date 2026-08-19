<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\AuditLogRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\ListingRepository;
use App\Repositories\MessageRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\ReportRepository;
use App\Repositories\UserRepository;
use App\Services\ReportService;
use App\Validators\ReportValidator;
use RuntimeException;
use Throwable;

final class ReportController
{
    private Request $request;
    private Response $response;
    private Auth $auth;
    private Csrf $csrf;
    private ReportService $reports;
    private ReportValidator $validator;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $session = new Session();
        $this->auth = new Auth($session);
        $this->csrf = new Csrf($session);
        $pdo = (new Database())->connection();
        $this->reports = new ReportService(
            new ReportRepository($pdo),
            new AuditLogRepository($pdo),
            new ListingRepository($pdo),
            new UserRepository($pdo),
            new ProfileRepository($pdo),
            new MessageRepository($pdo),
            new ConversationRepository($pdo)
        );
        $this->validator = new ReportValidator();
    }

    public function listingForm(string $id): ?string
    {
        return $this->form('listing', $id);
    }

    public function listingStore(string $id): ?string
    {
        return $this->store('listing', $id);
    }

    public function userForm(string $id): ?string
    {
        return $this->form('user', $id);
    }

    public function userStore(string $id): ?string
    {
        return $this->store('user', $id);
    }

    public function messageForm(string $id): ?string
    {
        return $this->form('message', $id);
    }

    public function messageStore(string $id): ?string
    {
        return $this->store('message', $id);
    }

    private function form(string $type, string $id, array $errors = [], array $old = []): ?string
    {
        $targetId = $this->routeId($id);

        if ($targetId === null) {
            return $this->notFound();
        }

        try {
            $context = match ($type) {
                'listing' => $this->reports->listingFormContext($this->userId(), $targetId),
                'user' => $this->reports->userFormContext($this->userId(), $targetId),
                default => $this->reports->messageFormContext($this->userId(), $targetId),
            };
        } catch (RuntimeException $exception) {
            return $this->mappedRuntimeResponse($exception);
        }

        return $this->view('reports/create.php', [
            'context' => $context,
            'errors' => $errors,
            'old' => $old,
            'csrf' => $this->csrf->token(),
            'action' => $this->url('/reports/' . $type . '/' . $targetId),
            'cancelUrl' => $this->url((string) $context['cancel_url_path']),
            'reasons' => ReportValidator::REASON_CODES,
        ]);
    }

    private function store(string $type, string $id): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            $this->response->send('Solicitud no válida.', 403);

            return null;
        }

        $targetId = $this->routeId($id);

        if ($targetId === null) {
            return $this->notFound();
        }

        $validation = $this->validator->validate($this->request->all());

        if (!$validation['valid']) {
            return $this->form($type, $id, $validation['errors'], [
                'reason_code' => (string) ($this->request->input('reason_code') ?? ''),
                'details' => (string) ($this->request->input('details') ?? ''),
            ]);
        }

        try {
            match ($type) {
                'listing' => $this->reports->reportListing(
                    $this->userId(),
                    $targetId,
                    $validation['data']['reason_code'],
                    $validation['data']['details']
                ),
                'user' => $this->reports->reportUser(
                    $this->userId(),
                    $targetId,
                    $validation['data']['reason_code'],
                    $validation['data']['details']
                ),
                default => $this->reports->reportMessage(
                    $this->userId(),
                    $targetId,
                    $validation['data']['reason_code'],
                    $validation['data']['details']
                ),
            };

            $this->csrf->regenerate();
            $context = match ($type) {
                'listing' => $this->reports->listingFormContext($this->userId(), $targetId),
                'user' => $this->reports->userFormContext($this->userId(), $targetId),
                default => $this->reports->messageFormContext($this->userId(), $targetId),
            };
            $this->response->redirect($this->url((string) $context['cancel_url_path']));

            return null;
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'duplicate') {
                return $this->form($type, $id, [
                    'reason_code' => 'Ya tienes un reporte abierto sobre este contenido.',
                ], [
                    'reason_code' => $validation['data']['reason_code'],
                    'details' => (string) ($validation['data']['details'] ?? ''),
                ]);
            }

            return $this->mappedRuntimeResponse($exception);
        } catch (Throwable) {
            $this->response->send('No se pudo enviar el reporte.', 500);

            return null;
        }
    }

    /** @param array<string, mixed> $data */
    private function view(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require dirname(__DIR__) . '/Views/' . $view;

        return (string) ob_get_clean();
    }

    private function routeId(string $id): ?int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return null;
        }

        return (int) $id;
    }

    private function userId(): int
    {
        return (int) $this->auth->id();
    }

    private function mappedRuntimeResponse(RuntimeException $exception): ?string
    {
        if ($exception->getMessage() === 'forbidden') {
            $this->response->forbidden();

            return null;
        }

        return $this->notFound();
    }

    private function notFound(): ?string
    {
        $this->response->notFound();

        return null;
    }

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }
}
