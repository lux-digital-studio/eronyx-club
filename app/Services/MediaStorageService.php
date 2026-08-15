<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class MediaStorageService
{
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    public const CONCEPTUAL_MAX_VIDEO_BYTES = 100 * 1024 * 1024;

    private const IMAGE_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    private const VIDEO_MIME_EXTENSIONS = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    private string $storageRoot;

    public function __construct(?string $storageRoot = null)
    {
        $this->storageRoot = $storageRoot ?? dirname(__DIR__, 2) . '/storage/private/media';
    }

    /** @param array<string, mixed>|null $file @return array{tmp_path: string, mime_type: string, media_type: string, visibility: string, size_bytes: int, checksum: string, storage_key: string, absolute_path: string} */
    public function prepareUpload(?array $file, string $usageType): array
    {
        if ($file === null || !isset($file['error'])) {
            throw new RuntimeException('Selecciona un archivo válido.');
        }

        $error = (int) $file['error'];
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($error));
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new RuntimeException('No se pudo leer el archivo subido.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);

        if (!is_string($mimeType)) {
            throw new RuntimeException('Formato de archivo no permitido.');
        }

        $isImage = array_key_exists($mimeType, self::IMAGE_MIME_EXTENSIONS);
        $isVideo = array_key_exists($mimeType, self::VIDEO_MIME_EXTENSIONS);

        if (!$isImage && !$isVideo) {
            throw new RuntimeException('Formato de archivo no permitido.');
        }

        if ($isVideo && $usageType !== 'private_content') {
            throw new RuntimeException('Los vídeos solo pueden subirse como contenido privado.');
        }

        if ($isImage && $size > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException('La imagen debe pesar entre 1 byte y 5 MB.');
        }

        if ($isVideo && $size > $this->effectiveMaxVideoBytes()) {
            throw new RuntimeException('El vídeo supera el tamaño permitido en este entorno.');
        }

        if ($size <= 0) {
            throw new RuntimeException('El archivo debe pesar al menos 1 byte.');
        }

        if ($isImage && getimagesize($tmpPath) === false) {
            throw new RuntimeException('El archivo no es una imagen válida.');
        }

        $checksum = hash_file('sha256', $tmpPath);
        if (!is_string($checksum) || $checksum === '') {
            throw new RuntimeException('No se pudo validar el archivo.');
        }

        $mediaType = $isVideo ? 'video' : 'image';
        $visibility = $usageType === 'private_content' ? 'private' : 'public';
        $extension = $isVideo ? self::VIDEO_MIME_EXTENSIONS[$mimeType] : self::IMAGE_MIME_EXTENSIONS[$mimeType];
        $relativeDirectory = date('Y/m');
        $storageKey = 'media/' . $relativeDirectory . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $absolutePath = $this->resolveStorageKey($storageKey);

        return [
            'tmp_path' => $tmpPath,
            'mime_type' => $mimeType,
            'media_type' => $mediaType,
            'visibility' => $visibility,
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
            throw new RuntimeException('No se pudo guardar el archivo.');
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
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño permitido.',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente. Inténtalo de nuevo.',
            UPLOAD_ERR_NO_FILE => 'Selecciona un archivo.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'No se pudo procesar el archivo subido.',
            default => 'No se pudo procesar el archivo subido.',
        };
    }

    public function effectiveMaxVideoBytes(): int
    {
        return min(
            self::CONCEPTUAL_MAX_VIDEO_BYTES,
            $this->iniBytes((string) ini_get('upload_max_filesize')),
            $this->iniBytes((string) ini_get('post_max_size'))
        );
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return self::CONCEPTUAL_MAX_VIDEO_BYTES;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $number = (float) $value;

        return (int) match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
