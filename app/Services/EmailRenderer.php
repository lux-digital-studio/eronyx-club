<?php

declare(strict_types=1);

namespace App\Services;

final class EmailRenderer
{
    /** @var array<string, mixed> */
    private array $app;

    public function __construct(?array $app = null)
    {
        $this->app = $app ?? require dirname(__DIR__, 2) . '/config/app.php';
    }

    public function url(string $path = '/'): string
    {
        return rtrim((string) ($this->app['url'] ?? ''), '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string, mixed> $data
     * @return array{subject: string, html: string, text: string}
     */
    public function render(string $template, array $data = []): array
    {
        $file = dirname(__DIR__) . '/Views/emails/' . $template . '.php';

        if (!is_file($file)) {
            return ['subject' => '', 'html' => '', 'text' => ''];
        }

        $data['e'] = [self::class, 'escape'];
        $data['appName'] = (string) ($this->app['name'] ?? 'ERONYX');
        $data['appUrl'] = $this->url('/');
        extract($data, EXTR_SKIP);

        ob_start();
        require $file;
        $html = (string) ob_get_clean();

        return [
            'subject' => isset($subject) && is_string($subject) ? $subject : '',
            'html' => $html,
            'text' => isset($text) && is_string($text) ? $text : '',
        ];
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function plain(mixed $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', (string) $value));
    }
}
