<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use App\Services\NotificationService;

final class NotificationController
{
    private Request $request;
    private Response $response;
    private Auth $auth;
    private Csrf $csrf;
    private NotificationService $notifications;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $session = new Session();
        $this->auth = new Auth($session);
        $this->csrf = new Csrf($session);
        $pdo = (new Database())->connection();
        $this->notifications = new NotificationService(
            new NotificationRepository($pdo),
            new UserRepository($pdo)
        );
    }

    public function index(): string
    {
        $result = $this->notifications->listForUser($this->userId(), $this->request->query('page'));

        return $this->view('account/notifications/index.php', [
            'notifications' => $result['items'],
            'total' => $result['total'],
            'perPage' => $result['perPage'],
            'currentPage' => $result['currentPage'],
            'lastPage' => $result['lastPage'],
            'unreadCount' => $this->notifications->countUnread($this->userId()),
            'csrf' => $this->csrf->token(),
            'indexUrl' => $this->url('/account/notifications'),
            'readAllUrl' => $this->url('/account/notifications/read-all'),
            'readBaseUrl' => $this->url('/account/notifications'),
            'accountUrl' => $this->url('/account'),
        ]);
    }

    public function markRead(string $id): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $notificationId = $this->routeId($id);

        if ($notificationId === null) {
            return $this->notFound();
        }

        if (!$this->notifications->markRead($notificationId, $this->userId())) {
            return $this->notFound();
        }

        $this->csrf->regenerate();
        $this->response->redirect($this->indexRedirect());

        return null;
    }

    public function markAllRead(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $this->notifications->markAllRead($this->userId());
        $this->csrf->regenerate();
        $this->response->redirect($this->url('/account/notifications'));

        return null;
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

    private function indexRedirect(): string
    {
        $page = $this->request->query('page');

        if (is_string($page) && preg_match('/\A[1-9][0-9]{0,8}\z/', $page) === 1) {
            return $this->url('/account/notifications') . '?page=' . $page;
        }

        return $this->url('/account/notifications');
    }

    private function rejectCsrf(): ?string
    {
        $this->response->send('Solicitud no válida.', 403);

        return null;
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
