<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\MediaRepository;
use App\Repositories\PrivateContentAccessRepository;
use App\Services\MediaStorageService;
use App\Services\PrivateContentAccessService;
use RuntimeException;

final class MediaController
{
    private Response $response;
    private Auth $auth;
    private MediaRepository $media;
    private MediaStorageService $storage;
    private PrivateContentAccessService $privateAccess;

    public function __construct()
    {
        $this->response = new Response();
        $session = new Session();
        $this->auth = new Auth($session);
        $pdo = (new Database())->connection();
        $this->media = new MediaRepository($pdo);
        $this->storage = new MediaStorageService();
        $this->privateAccess = new PrivateContentAccessService(
            new PrivateContentAccessRepository($pdo),
            null,
            null,
            $pdo
        );
    }

    public function show(string $id): ?string
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            $this->response->notFound();

            return null;
        }

        $rows = $this->media->findDeliveryCandidates((int) $id);

        if ($rows === []) {
            $this->response->notFound();

            return null;
        }

        $media = $rows[0];

        if (!$this->isActiveMedia($media)) {
            $this->response->notFound();

            return null;
        }

        $access = $this->accessContext($rows);

        if ($access === null) {
            $this->response->notFound();

            return null;
        }

        try {
            $path = $this->storage->resolveStorageKey((string) $media['storage_key']);
        } catch (RuntimeException) {
            $this->response->notFound();

            return null;
        }

        if (!is_file($path)) {
            $this->response->notFound();

            return null;
        }

        $this->sendFile($path, $media, $access);

        return null;
    }

    /** @param array<string, mixed> $media */
    private function isActiveMedia(array $media): bool
    {
        return in_array($media['media_type'], ['image', 'video'], true)
            && in_array($media['visibility'], ['public', 'private'], true)
            && $media['status'] === 'active'
            && $media['deleted_at'] === null;
    }

    /** @param list<array<string, mixed>> $rows */
    private function accessContext(array $rows): ?string
    {
        $media = $rows[0];

        if ($media['visibility'] === 'public') {
            return $this->publicAccessContext($rows);
        }

        if ($media['visibility'] === 'private') {
            return $this->privateAccessContext($rows);
        }

        return null;
    }

    /** @param list<array<string, mixed>> $rows */
    private function publicAccessContext(array $rows): ?string
    {
        $authUserId = $this->auth->id();

        foreach ($rows as $row) {
            if (
                $authUserId !== null
                && $row['owner_user_id'] === $authUserId
                && $row['listing_owner_user_id'] === $authUserId
                && $row['listing_id'] !== null
                && $row['listing_deleted_at'] === null
            ) {
                return 'owner';
            }
        }

        foreach ($rows as $row) {
            if (
                in_array($row['usage_type'], ['cover', 'gallery', 'preview'], true)
                && $row['listing_status'] === 'published'
                && in_array($row['listing_visibility'], ['public', 'unlisted'], true)
                && $row['published_at'] !== null
                && $row['listing_deleted_at'] === null
            ) {
                return 'public';
            }
        }

        foreach ($rows as $row) {
            if (
                $row['avatar_profile_id'] !== null
                && $row['avatar_profile_deleted_at'] === null
                && $row['media_type'] === 'image'
                && $row['visibility'] === 'public'
                && $row['status'] === 'active'
            ) {
                return 'public';
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $rows */
    private function privateAccessContext(array $rows): ?string
    {
        $authUserId = $this->auth->id();

        foreach ($rows as $row) {
            if (
                $row['usage_type'] === 'private_content'
                && $row['listing_id'] !== null
                && $row['listing_deleted_at'] === null
                && $this->privateAccess->canAccessListingPrivateContent($authUserId, (int) $row['listing_id'])
            ) {
                return ((int) $row['listing_owner_user_id'] === $authUserId) ? 'owner' : 'private';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $media */
    private function sendFile(string $path, array $media, string $access): void
    {
        $size = filesize($path);

        if ($size === false) {
            $this->response->notFound();

            return;
        }

        header('Content-Type: ' . (string) $media['mime_type']);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');

        $mtime = filemtime($path);
        $etag = $this->publicEtag($media, (int) $size, $mtime);

        if ($access === 'public') {
            // Media IDs are immutable (replacement creates a new file/id). 1 day + ETag revalidation.
            header('Cache-Control: public, max-age=86400');
            header('ETag: ' . $etag);

            if ($mtime !== false) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            }

            $rangeHeader = (string) ($_SERVER['HTTP_RANGE'] ?? '');

            if ($rangeHeader === '' && $this->isFreshPublicCopy($etag, $mtime)) {
                http_response_code(304);
                exit;
            }
        } else {
            header('Cache-Control: private, no-store');
            header('Pragma: no-cache');
        }

        if ($media['media_type'] === 'video') {
            header('Accept-Ranges: bytes');
            $range = $this->requestedRange((int) $size);

            if ($range === false) {
                http_response_code(416);
                header('Content-Range: bytes */' . (string) $size);
                exit;
            }

            if (is_array($range)) {
                [$start, $end] = $range;
                $length = $end - $start + 1;
                http_response_code(206);
                header('Content-Length: ' . (string) $length);
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
                $this->streamRange($path, $start, $length);
                exit;
            }
        }

        header('Content-Length: ' . (string) $size);
        readfile($path);
        exit;
    }

    /** @param array<string, mixed> $media */
    private function publicEtag(array $media, int $size, int|false $mtime): string
    {
        $checksum = preg_replace('/[^a-f0-9]/i', '', (string) ($media['checksum'] ?? '')) ?? '';

        if (strlen($checksum) >= 32) {
            return '"' . strtolower($checksum) . '"';
        }

        return '"' . hash('sha256', (string) $size . ':' . (string) $mtime) . '"';
    }

    private function isFreshPublicCopy(string $etag, int|false $mtime): bool
    {
        $noneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));

        if ($noneMatch === '*') {
            return true;
        }

        if ($noneMatch !== '') {
            foreach (explode(',', $noneMatch) as $candidate) {
                $candidate = trim(str_replace('W/', '', $candidate));

                if ($candidate === $etag) {
                    return true;
                }
            }
        }

        $modifiedSince = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));

        if ($mtime === false || $modifiedSince === '') {
            return false;
        }

        $since = strtotime($modifiedSince);

        return $since !== false && $mtime <= $since;
    }

    /** @return array{0: int, 1: int}|false|null */
    private function requestedRange(int $size): array|false|null
    {
        $header = (string) ($_SERVER['HTTP_RANGE'] ?? '');

        if ($header === '') {
            return null;
        }

        if (preg_match('/\\Abytes=(\\d*)-(\\d*)\\z/', $header, $matches) !== 1) {
            return false;
        }

        $startRaw = $matches[1];
        $endRaw = $matches[2];

        if ($startRaw === '' && $endRaw === '') {
            return false;
        }

        if (($startRaw !== '' && (strlen($startRaw) > 15 || !ctype_digit($startRaw)))
            || ($endRaw !== '' && (strlen($endRaw) > 15 || !ctype_digit($endRaw)))
        ) {
            return false;
        }

        if ($startRaw === '') {
            $suffix = (int) $endRaw;

            if ($suffix <= 0) {
                return false;
            }

            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int) $startRaw;
            $end = $endRaw === '' ? $size - 1 : (int) $endRaw;
        }

        if ($start < 0 || $end < $start || $start >= $size) {
            return false;
        }

        return [$start, min($end, $size - 1)];
    }

    private function streamRange(string $path, int $start, int $length): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->response->notFound();

            return;
        }

        fseek($handle, $start);
        $remaining = $length;

        while ($remaining > 0 && !feof($handle)) {
            $chunkSize = min(8192, $remaining);
            $chunk = fread($handle, $chunkSize);

            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
            $remaining -= strlen($chunk);
        }

        fclose($handle);
    }
}
