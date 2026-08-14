<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Session;

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
        return $this->view('account/index.php', [
            'csrf' => $this->csrf->token(),
            'logoutUrl' => $this->url('/logout'),
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
