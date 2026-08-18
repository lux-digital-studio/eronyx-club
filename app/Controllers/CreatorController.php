<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;
use App\Repositories\ProfileRepository;

final class CreatorController
{
    public function index(): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $auth = new Auth(new Session());
        $profile = null;

        if ($auth->id() !== null) {
            $profile = (new ProfileRepository((new Database())->connection()))->findPublicCreatorByUserId((int) $auth->id());
        }

        $publicProfileUrl = is_array($profile)
            ? rtrim((string) $config['url'], '/') . '/creator/' . $profile['username']
            : null;

        ob_start();
        require dirname(__DIR__) . '/Views/creator/index.php';

        return (string) ob_get_clean();
    }
}
