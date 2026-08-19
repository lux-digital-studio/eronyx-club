<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Repositories\ConversationRepository;
use App\Repositories\NotificationRepository;

final class AccountController
{
    private Session $session;
    private Csrf $csrf;

    public function __construct()
    {
        $this->session = new Session();
        $this->csrf = new Csrf($this->session);
    }

    public function index(): string
    {
        $auth = new Auth($this->session);
        $unreadCount = 0;
        $notificationUnreadCount = 0;
        $userId = $auth->id();

        if ($userId !== null) {
            $pdo = (new Database())->connection();
            $unreadCount = (new ConversationRepository($pdo))->unreadConversationCount($userId);
            $notificationUnreadCount = (new NotificationRepository($pdo))->countUnreadForUser($userId);
        }

        return $this->view('account/index.php', [
            'csrf' => $this->csrf->token(),
            'logoutUrl' => $this->url('/logout'),
            'ordersUrl' => $this->url('/account/orders'),
            'favoritesUrl' => $this->url('/account/favorites'),
            'messagesUrl' => $this->url('/account/messages'),
            'notificationsUrl' => $this->url('/account/notifications'),
            'unreadCount' => $unreadCount,
            'notificationUnreadCount' => $notificationUnreadCount,
            'profileUrl' => $this->url('/account/profile'),
            'securityUrl' => $this->url('/account/security/password'),
            'creatorStatusUrl' => $this->url('/account/creator/status'),
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
}
