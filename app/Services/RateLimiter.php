<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class RateLimiter
{
    /** @var callable():int */
    private $clock;
    private string $storageRoot;

    public function __construct(?string $storageRoot = null, ?callable $clock = null)
    {
        $this->storageRoot = $storageRoot ?? dirname(__DIR__, 2) . '/storage/cache/rate-limits';
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        $state = $this->read($key);

        if ($state === null) {
            return false;
        }

        return $state['count'] >= $maxAttempts && $state['reset_at'] > $this->now();
    }

    public function hit(string $key, int $decaySeconds): int
    {
        $now = $this->now();
        $path = $this->pathFor($key);
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('No se pudo registrar el límite de solicitudes.');
        }

        try {
            flock($handle, LOCK_EX);
            $contents = stream_get_contents($handle);
            $state = is_string($contents) ? $this->decode($contents) : null;

            if ($state === null || $state['reset_at'] <= $now) {
                $state = [
                    'count' => 1,
                    'reset_at' => $now + max(1, $decaySeconds),
                ];
            } else {
                $state['count']++;
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) json_encode($state));
            fflush($handle);

            return $state['count'];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function retryAfter(string $key): int
    {
        $state = $this->read($key);

        if ($state === null) {
            return 0;
        }

        return max(0, $state['reset_at'] - $this->now());
    }

    public function reset(string $key): void
    {
        $path = $this->pathFor($key);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function storageRoot(): string
    {
        return $this->normalizedRoot();
    }

    /** @return array{count: int, reset_at: int}|null */
    private function read(string $key): ?array
    {
        $path = $this->pathFor($key);

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $this->decode($contents) : null;
    }

    /** @return array{count: int, reset_at: int}|null */
    private function decode(string $contents): ?array
    {
        $data = json_decode($contents, true);

        if (!is_array($data) || !isset($data['count'], $data['reset_at'])) {
            return null;
        }

        $now = $this->now();
        $resetAt = (int) $data['reset_at'];

        if ($resetAt <= $now) {
            return null;
        }

        return [
            'count' => max(0, (int) $data['count']),
            'reset_at' => $resetAt,
        ];
    }

    private function pathFor(string $key): string
    {
        $hash = hash('sha256', $key);

        if (preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
            throw new RuntimeException('Clave de límite inválida.');
        }

        $root = $this->normalizedRoot();
        $path = $root . DIRECTORY_SEPARATOR . $hash . '.json';

        if (!$this->isWithinRoot($path, $root)) {
            throw new RuntimeException('Clave de límite inválida.');
        }

        return $path;
    }

    private function normalizedRoot(): string
    {
        if (!is_dir($this->storageRoot) && !mkdir($this->storageRoot, 0775, true) && !is_dir($this->storageRoot)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento de límites.');
        }

        $root = realpath($this->storageRoot);

        if ($root === false) {
            throw new RuntimeException('No se pudo resolver el almacenamiento de límites.');
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function isWithinRoot(string $path, string $root): bool
    {
        $normalizedPath = strtolower(str_replace('\\', '/', $path));
        $normalizedRoot = strtolower(str_replace('\\', '/', rtrim($root, '/\\')));

        return $normalizedPath === $normalizedRoot
            || str_starts_with($normalizedPath, $normalizedRoot . '/');
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
