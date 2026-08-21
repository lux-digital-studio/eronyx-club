<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Repositories\ConversationRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use App\Services\UserConsentService;

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
        $emailVerified = false;
        $consents = [];
        $userId = $auth->id();

        if ($userId !== null) {
            $pdo = (new Database())->connection();
            $unreadCount = (new ConversationRepository($pdo))->unreadConversationCount($userId);
            $notificationUnreadCount = (new NotificationRepository($pdo))->countUnreadForUser($userId);
            $emailVerified = (new UserRepository($pdo))->isEmailVerified($userId);
            $consents = (new UserConsentService($pdo))->findForUser($userId);
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
            'legalUrl' => $this->url('/account/legal'),
            'verifyEmailUrl' => $this->url('/account/verify-email'),
            'emailVerified' => $emailVerified,
            'consents' => $consents,
        ]);
    }

    public function legal(): string
    {
        $auth = new Auth($this->session);
        $userId = (int) $auth->id();
        $pdo = (new Database())->connection();
        $service = new UserConsentService($pdo);

        return $this->view('account/legal/index.php', [
            'consents' => $service->findForUser($userId),
            'current' => [
                'terms' => $service->hasAccepted($userId, 'terms'),
                'privacy' => $service->hasAccepted($userId, 'privacy'),
                'creator_rules' => $service->hasAccepted($userId, 'creator_rules'),
                'content_policy' => $service->hasAccepted($userId, 'content_policy'),
                'age_declaration' => $service->hasAccepted($userId, 'age_declaration'),
            ],
            'versions' => [
                'terms' => $service->version('terms'),
                'privacy' => $service->version('privacy'),
                'creator_rules' => $service->version('creator_rules'),
                'content_policy' => $service->version('content_policy'),
                'age_declaration' => $service->version('age_declaration'),
            ],
            'accountUrl' => $this->url('/account'),
            'termsUrl' => $this->url('/terms'),
            'privacyUrl' => $this->url('/privacy'),
            'creatorRulesUrl' => $this->url('/creator-rules'),
            'contentPolicyUrl' => $this->url('/content-policy'),
            'agePolicyUrl' => $this->url('/age-policy'),
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
