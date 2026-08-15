<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\MediaRepository;
use App\Services\MediaStorageService;
use RuntimeException;

final class MediaController
{
    private Response $response;
    private Auth $auth;
    private MediaRepository $media;
    private MediaStorageService $storage;

    public function __construct()
    {
        $this->response = new Response();
        $session = new Session();
        $this->auth = new Auth($session);
        $this->media = new MediaRepository((new Database())->connection());
        $this->storage = new MediaStorageService();
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

        if (!$this->isActiveImage($media)) {
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

        header('Content-Type: ' . (string) $media['mime_type']);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        header('Cache-Control: ' . ($access === 'public' ? 'public, max-age=3600' : 'private, no-store'));
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    /** @param array<string, mixed> $media */
    private function isActiveImage(array $media): bool
    {
        return $media['media_type'] === 'image'
            && $media['visibility'] === 'public'
            && $media['status'] === 'active'
            && $media['deleted_at'] === null;
    }

    /** @param list<array<string, mixed>> $rows */
    private function accessContext(array $rows): ?string
    {
        $authUserId = $this->auth->id();

        foreach ($rows as $row) {
            if (
                $authUserId !== null
                && $row['owner_user_id'] === $authUserId
                && $row['listing_owner_user_id'] === $authUserId
                && $row['listing_id'] !== null
            ) {
                return 'owner';
            }
        }

        foreach ($rows as $row) {
            if (
                in_array($row['usage_type'], ['cover', 'gallery', 'preview'], true)
                && $row['listing_status'] === 'published'
                && $row['listing_visibility'] === 'public'
                && $row['published_at'] !== null
                && $row['listing_deleted_at'] === null
            ) {
                return 'public';
            }
        }

        return null;
    }
}
