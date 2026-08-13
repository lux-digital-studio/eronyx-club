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
}