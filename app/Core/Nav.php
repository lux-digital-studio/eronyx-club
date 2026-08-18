<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\ConversationRepository;
use App\Repositories\CreatorApplicationRepository;
use App\Repositories\UserRepository;

final class Nav
{
    /**
     * Presentation-only nav flags. Does not replace middleware.
     *
     * @return array{
     *   authenticated: bool,
     *   csrf: string|null,
     *   path: string,
     *   showCreator: bool,
     *   showModerator: bool,
     *   showAdmin: bool,
     *   unreadCount: int
     * }
     */
    public static function context(): array
    {
        $session = new Session();
        $auth = new Auth($session);
        $authenticated = $auth->check();
        $showCreator = false;
        $showModerator = false;
        $showAdmin = false;
        $csrf = null;
        $unreadCount = 0;

        if ($authenticated) {
            $pdo = (new Database())->connection();
            $authorization = new Authorization($auth, new UserRepository($pdo));
            $showModerator = $authorization->hasRole('moderator');
            $showAdmin = $authorization->hasRole('admin');

            if ($authorization->hasRole('creator')) {
                $userId = $auth->id();
                $showCreator = $userId !== null
                    && (new CreatorApplicationRepository($pdo))->hasActiveCreatorProfile($userId);
            }

            $userId = $auth->id();

            if ($userId !== null) {
                $unreadCount = (new ConversationRepository($pdo))->unreadConversationCount($userId);
            }

            $csrf = (new Csrf($session))->token();
        }

        return [
            'authenticated' => $authenticated,
            'csrf' => $csrf,
            'path' => (new Request())->path(),
            'showCreator' => $showCreator,
            'showModerator' => $showModerator,
            'showAdmin' => $showAdmin,
            'unreadCount' => $unreadCount,
        ];
    }
}
