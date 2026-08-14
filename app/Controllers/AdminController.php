<?php

declare(strict_types=1);

namespace App\Controllers;

final class AdminController
{
    public function index(): string
    {
        ob_start();
        require dirname(__DIR__) . '/Views/admin/index.php';

        return (string) ob_get_clean();
    }
}
