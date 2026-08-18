<?php

declare(strict_types=1);

namespace App\Core;

final class Layout
{
    public static function render(string $title, string $content, string $bodyClass = ''): void
    {
        $pageTitle = $title;
        $nav = Nav::context();

        require dirname(__DIR__) . '/Views/layouts/main.php';
    }

    public static function url(string $path = '/'): string
    {
        static $base = null;

        if ($base === null) {
            $config = require dirname(__DIR__, 2) . '/config/app.php';
            $base = rtrim((string) $config['url'], '/');
        }

        return $base . '/' . ltrim($path, '/');
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
