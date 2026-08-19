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

        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new RuntimeException('No se pudo leer el archivo subido.');
        }

        $size = (int) filesize($tmpPath);

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

        if ($isImage && $mimeType === 'image/jpeg') {
            $stripped = $this->reencodeJpegWithoutExif($tmpPath);

            if ($stripped !== $tmpPath) {
                $tmpPath = $stripped;
                $size = (int) filesize($tmpPath);
            }
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

        if (is_uploaded_file($prepared['tmp_path'])) {
            $moved = move_uploaded_file($prepared['tmp_path'], $prepared['absolute_path']);
        } else {
            $moved = @rename($prepared['tmp_path'], $prepared['absolute_path']);

            if (!$moved && is_file($prepared['tmp_path'])) {
                $moved = copy($prepared['tmp_path'], $prepared['absolute_path']);

                if ($moved) {
                    unlink($prepared['tmp_path']);
                }
            }
        }

        if (!$moved) {
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
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storageKey);
        $fullPath = $root . DIRECTORY_SEPARATOR . substr($path, strlen('media' . DIRECTORY_SEPARATOR));
        $normalizedFull = str_replace('\\', '/', $fullPath);
        $normalizedRoot = str_replace('\\', '/', $root);

        if (!str_starts_with(strtolower($normalizedFull), strtolower($normalizedRoot) . '/')) {
            throw new RuntimeException('Ruta de media inválida.');
        }

        $directory = dirname($fullPath);

        if (is_dir($directory)) {
            $realDirectory = realpath($directory);

            if ($realDirectory === false || !$this->isWithinRoot($realDirectory, $root)) {
                throw new RuntimeException('Ruta de media inválida.');
            }
        }

        if (is_file($fullPath)) {
            $realFile = realpath($fullPath);

            if ($realFile === false || !$this->isWithinRoot($realFile, $root)) {
                throw new RuntimeException('Ruta de media inválida.');
            }

            return $realFile;
        }

        return $fullPath;
    }

    public function storageRoot(): string
    {
        return $this->normalizedRoot();
    }

    private function isWithinRoot(string $path, string $root): bool
    {
        $normalizedPath = strtolower(str_replace('\\', '/', $path));
        $normalizedRoot = strtolower(str_replace('\\', '/', rtrim($root, '/\\')));

        return $normalizedPath === $normalizedRoot
            || str_starts_with($normalizedPath, $normalizedRoot . '/');
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

    /**
     * Re-encode JPEG with GD to drop EXIF (including GPS) while preserving
     * orientation. If GD is unavailable, original bytes are kept.
     */
    private function reencodeJpegWithoutExif(string $tmpPath): string
    {
        if (!function_exists('imagecreatefromjpeg') || !function_exists('imagejpeg')) {
            return $tmpPath;
        }

        $image = @imagecreatefromjpeg($tmpPath);

        if ($image === false) {
            return $tmpPath;
        }

        $orientation = 1;

        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmpPath);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        }

        $image = $this->applyJpegOrientation($image, $orientation);
        $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eronyx_jpg_' . bin2hex(random_bytes(8)) . '.jpg';
        $ok = imagejpeg($image, $target, 90);
        imagedestroy($image);

        if (!$ok || !is_file($target)) {
            return $tmpPath;
        }

        return $target;
    }

    /** @param \GdImage $image @return \GdImage */
    private function applyJpegOrientation($image, int $orientation)
    {
        return match ($orientation) {
            3 => imagerotate($image, 180, 0) ?: $image,
            6 => imagerotate($image, -90, 0) ?: $image,
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
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
