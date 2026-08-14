<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function send(string $content, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        echo $content;
    }

    public function notFound(): void
    {
        $this->send('404 - Not Found', 404);
    }

    public function redirect(string $url, int $statusCode = 302): void
    {
        http_response_code($statusCode);
        header('Location: ' . $url, true, $statusCode);
        exit;
    }
}
