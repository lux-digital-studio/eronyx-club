<?php

declare(strict_types=1);

namespace App\Controllers;

final class HomeController
{
    public function index(): string
    {
        ob_start();
        require dirname(__DIR__) . '/Views/home/index.php';

        return (string) ob_get_clean();
    }

    public function marketplace(): string
    {
        return 'ERONYX - Marketplace';
    }

    public function login(): string
    {
        return 'ERONYX - Login';
    }

    public function register(): string
    {
        return 'ERONYX - Register';
    }
}
