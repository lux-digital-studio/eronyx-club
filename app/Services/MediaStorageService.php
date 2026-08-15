<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class MediaStorageService
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private string $storageRoot;

    public function __construct(?string $storageRoot = null)
    {
        $this->storageRoot = $storageRoot ?? dirname(__DIR__, 2) . '/storage/private/media';
    }

    /** @param array<string, mixed>|null $file @return array{tmp_path: string, mime_type: string, size_bytes: int, checksum: string, storage_key: string, absolute_path: string} */
    public function prepareUpload(?array $file): array
    {
        if ($file === null || !isset($file['error'])) {
            throw new RuntimeException('Selecciona una imagen válida.');
        }

        $error = (int) $file['error'];
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($error));
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new RuntimeException('No se pudo leer la imagen subida.');
        }

        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('La imagen debe pesar entre 1 byte y 5 MB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);

        if (!is_string($mimeType) || !array_key_exists($mimeType, self::MIME_EXTENSIONS)) {
            throw new RuntimeException('Formato de imagen no permitido.');
        }

        if (getimagesize($tmpPath) === false) {
            throw new RuntimeException('El archivo no es una imagen válida.');
        }

        $checksum = hash_file('sha256', $tmpPath);
        if (!is_string($checksum) || $checksum === '') {
            throw new RuntimeException('No se pudo validar la imagen.');
        }

        $extension = self::MIME_EXTENSIONS[$mimeType];
        $relativeDirectory = date('Y/m');
        $storageKey = 'media/' . $relativeDirectory . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $absolutePath = $this->resolveStorageKey($storageKey);

        return [
            'tmp_path' => $tmpPath,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'checksum' => $checksum,
            'storage_key' => $storageKey,
            'absolute_path' => $absolutePath,
        ];
    }

    /** @param array{tmp_path: string, absolute_path: string} $prepared */
    public function movePreparedUpload(array $prepared): void
    {
        $directory = dirname($prepared['absolute_path']);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento.');
        }

        if (!move_uploaded_file($prepared['tmp_path'], $prepared['absolute_path'])) {
            throw new RuntimeException('No se pudo guardar la imagen.');
        }
    }

    public function deleteByStorageKey(string $storageKey): bool
    {
        $path = $this->resolveStorageKey($storageKey);

        return !is_file($path) || unlink($path);
    }

    public function resolveStorageKey(string $storageKey): string
    {
        if (
            $storageKey === ''
            || str_contains($storageKey, "\0")
            || str_contains($storageKey, '..')
            || str_contains($storageKey, '\\')
            || preg_match('/\A(?:[a-zA-Z]:|\/|https?:\/\/)/', $storageKey) === 1
            || !str_starts_with($storageKey, 'media/')
        ) {
            throw new RuntimeException('Ruta de media inválida.');
        }

        $root = $this->normalizedRoot();
        $path = str_replace('/', DIRECTORY_SEPARATOR, $storageKey);
        $fullPath = $root . DIRECTORY_SEPARATOR . substr($path, strlen('media' . DIRECTORY_SEPARATOR));
        $directory = dirname($fullPath);
        $realDirectory = is_dir($directory) ? realpath($directory) : realpath($this->storageRoot);

        if ($realDirectory === false || !str_starts_with(str_replace('\\', '/', $directory), str_replace('\\', '/', $root))) {
            throw new RuntimeException('Ruta de media inválida.');
        }

        return $fullPath;
    }

    public function storageRoot(): string
    {
        return $this->normalizedRoot();
    }

    private function normalizedRoot(): string
    {
        if (!is_dir($this->storageRoot) && !mkdir($this->storageRoot, 0775, true) && !is_dir($this->storageRoot)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento.');
        }

        $root = realpath($this->storageRoot);

        if ($root === false) {
            throw new RuntimeException('No se pudo resolver el almacenamiento.');
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La imagen supera el tamaño permitido.',
            UPLOAD_ERR_PARTIAL => 'La imagen se subió parcialmente. Inténtalo de nuevo.',
            UPLOAD_ERR_NO_FILE => 'Selecciona una imagen.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'No se pudo procesar la imagen subida.',
            default => 'No se pudo procesar la imagen subida.',
        };
    }
}
