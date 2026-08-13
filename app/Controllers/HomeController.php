<?php

declare(strict_types=1);

namespace App\Controllers;

final class HomeController
{
    public function index(): string
    {
        return 'ERONYX - Home';
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