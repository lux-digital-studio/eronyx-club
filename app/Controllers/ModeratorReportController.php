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
use App\Repositories\CreatorApplicationRepository;
use App\Repositories\ListingRepository;
use App\Repositories\MessageRepository;
use App\Repositories\ModerationActionRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\ReportRepository;
use App\Repositories\UserRepository;
use App\Services\ModerationService;
use RuntimeException;
use Throwable;

final class ModeratorReportController
{
    private Request $request;
    private Response $response;
    private Auth $auth;
    private Csrf $csrf;
    private ModerationService $moderation;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $session = new Session();
        $this->auth = new Auth($session);
        $this->csrf = new Csrf($session);
        $pdo = (new Database())->connection();
        $this->moderation = new ModerationService(
            new ReportRepository($pdo),
            new ModerationActionRepository($pdo),
            new AuditLogRepository($pdo),
            new ListingRepository($pdo),
            new UserRepository($pdo),
            new ProfileRepository($pdo),
            new MessageRepository($pdo),
            new CreatorApplicationRepository($pdo)
        );
    }

    public function index(): string
    {
        return $this->view('moderator/reports/index.php', [
            'reports' => $this->moderation->queue(),
            'baseUrl' => $this->url('/moderator/reports'),
        ]);
    }

    public function show(string $id): ?string
    {
        $reportId = $this->routeId($id);

        if ($reportId === null) {
            return $this->notFound();
        }

        try {
            $detail = $this->moderation->reportDetail($reportId);
        } catch (RuntimeException $exception) {
            return $this->mappedRuntimeResponse($exception);
        }

        $target = $detail['target'];
        $listingId = isset($target['listing']['id']) ? (int) $target['listing']['id'] : null;
        $userId = ($detail['report']['target_type'] ?? '') === 'user'
            ? (int) $detail['report']['target_id']
            : null;

        return $this->view('moderator/reports/show.php', [
            'report' => $detail['report'],
            'reporter' => $detail['reporter'],
            'target' => $target,
            'actions' => $detail['actions'],
            'audits' => $detail['audits'],
            'csrf' => $this->csrf->token(),
            'reviewUrl' => $this->url('/moderator/reports/' . $reportId . '/review'),
            'resolveUrl' => $this->url('/moderator/reports/' . $reportId . '/resolve'),
            'dismissUrl' => $this->url('/moderator/reports/' . $reportId . '/dismiss'),
            'suspendListingUrl' => $listingId !== null ? $this->url('/moderator/listings/' . $listingId . '/suspend') : null,
            'restoreListingUrl' => $listingId !== null ? $this->url('/moderator/listings/' . $listingId . '/restore') : null,
            'suspendCreatorUrl' => $userId !== null ? $this->url('/moderator/creators/' . $userId . '/suspend') : null,
            'restoreCreatorUrl' => $userId !== null ? $this->url('/moderator/creators/' . $userId . '/restore') : null,
            'indexUrl' => $this->url('/moderator/reports'),
        ]);
    }

    public function review(string $id): ?string
    {
        return $this->mutateReport($id, 'review');
    }

    public function resolve(string $id): ?string
    {
        return $this->mutateReport($id, 'resolve');
    }

    public function dismiss(string $id): ?string
    {
        return $this->mutateReport($id, 'dismiss');
    }

    public function suspendCreator(string $userId): ?string
    {
        return $this->mutateTarget($userId, 'creator_suspend');
    }

    public function restoreCreator(string $userId): ?string
    {
        return $this->mutateTarget($userId, 'creator_restore');
    }

    private function mutateReport(string $id, string $action): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            $this->response->send('Solicitud no válida.', 403);

            return null;
        }

        $reportId = $this->routeId($id);

        if ($reportId === null) {
            return $this->notFound();
        }

        try {
            match ($action) {
                'review' => $this->moderation->markInReview($this->userId(), $reportId),
                'resolve' => $this->moderation->resolve($this->userId(), $reportId),
                default => $this->moderation->dismiss($this->userId(), $reportId),
            };
            $this->csrf->regenerate();
            $this->response->redirect($this->url('/moderator/reports/' . $reportId));

            return null;
        } catch (RuntimeException $exception) {
            return $this->mappedRuntimeResponse($exception);
        } catch (Throwable) {
            $this->response->send('No se pudo actualizar el reporte.', 500);

            return null;
        }
    }

    private function mutateTarget(string $id, string $action): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            $this->response->send('Solicitud no válida.', 403);

            return null;
        }

        $targetId = $this->routeId($id);

        if ($targetId === null) {
            return $this->notFound();
        }

        try {
            if ($action === 'creator_suspend') {
                $this->moderation->suspendCreator($this->userId(), $targetId);
            } else {
                $this->moderation->restoreCreator($this->userId(), $targetId);
            }

            $this->csrf->regenerate();
            $this->response->redirect($this->url('/moderator/reports'));

            return null;
        } catch (RuntimeException $exception) {
            return $this->mappedRuntimeResponse($exception);
        } catch (Throwable) {
            $this->response->send('No se pudo completar la acción.', 500);

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
        if (in_array($exception->getMessage(), ['forbidden', 'role_missing'], true)) {
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
