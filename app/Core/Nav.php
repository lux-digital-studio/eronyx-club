<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\ConversationRepository;
use App\Repositories\CreatorApplicationRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\ReportRepository;
use App\Repositories\UserRepository;

final class Nav
{
    /** @var array<string, mixed>|null */
    private static ?array $requestContext = null;

    /**
     * Presentation-only nav flags. Does not replace middleware.
     * Memoized per PHP request only — not stored in the session.
     *
     * @return array{
     *   authenticated: bool,
     *   csrf: string|null,
     *   path: string,
     *   showCreator: bool,
     *   showModerator: bool,
     *   showAdmin: bool,
     *   unreadCount: int,
     *   notificationUnreadCount: int,
     *   openReportCount: int
     * }
     */
    public static function context(): array
    {
        if (self::$requestContext !== null) {
            return self::$requestContext;
        }

        $session = new Session();
        $auth = new Auth($session);
        $authenticated = $auth->check();
        $showCreator = false;
        $showModerator = false;
        $showAdmin = false;
        $csrf = null;
        $unreadCount = 0;
        $notificationUnreadCount = 0;
        $openReportCount = 0;

        if ($authenticated) {
            $pdo = (new Database())->connection();
            $authorization = new Authorization($auth, new UserRepository($pdo));
            $showModerator = $authorization->hasRole('moderator');
            $showAdmin = $authorization->hasRole('admin');

            if ($showModerator) {
                $openReportCount = (new ReportRepository($pdo))->countOpenReports();
            }

            if ($authorization->hasRole('creator')) {
                $userId = $auth->id();
                $showCreator = $userId !== null
                    && (new CreatorApplicationRepository($pdo))->hasActiveCreatorProfile($userId);
            }

            $userId = $auth->id();

            if ($userId !== null) {
                $unreadCount = (new ConversationRepository($pdo))->unreadConversationCount($userId);
                $notificationUnreadCount = (new NotificationRepository($pdo))->countUnreadForUser($userId);
            }

            $csrf = (new Csrf($session))->token();
        }

        self::$requestContext = [
            'authenticated' => $authenticated,
            'csrf' => $csrf,
            'path' => (new Request())->path(),
            'showCreator' => $showCreator,
            'showModerator' => $showModerator,
            'showAdmin' => $showAdmin,
            'unreadCount' => $unreadCount,
            'notificationUnreadCount' => $notificationUnreadCount,
            'openReportCount' => $openReportCount,
        ];

        return self::$requestContext;
    }
}
