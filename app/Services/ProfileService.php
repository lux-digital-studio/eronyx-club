<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\MediaRepository;
use App\Repositories\ProfileRepository;
use RuntimeException;
use Throwable;

final class ProfileService
{
    private \PDO $pdo;
    private ProfileRepository $profiles;
    private MediaRepository $media;
    private MediaStorageService $storage;

    public function __construct(
        private readonly Auth $auth,
        ?\PDO $pdo = null,
        ?ProfileRepository $profiles = null,
        ?MediaRepository $media = null,
        ?MediaStorageService $storage = null
    ) {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->profiles = $profiles ?? new ProfileRepository($this->pdo);
        $this->media = $media ?? new MediaRepository($this->pdo);
        $this->storage = $storage ?? new MediaStorageService();
    }

    /** @return array<string, mixed> */
    public function getOwnProfile(): array
    {
        $profile = $this->profiles->findByUserId($this->userId());

        if ($profile === null || $profile['deleted_at'] !== null) {
            throw new RuntimeException('Perfil no encontrado.');
        }

        return $profile;
    }

    /** @param array{display_name: string, username: string, bio: string|null} $data */
    public function updateOwnProfile(array $data): bool
    {
        $userId = $this->userId();

        if ($this->profiles->usernameExists($data['username'], $userId)) {
            throw new RuntimeException('Este username ya está en uso.');
        }

        return $this->profiles->updateProfile($userId, $data);
    }

    /** @param array<string, mixed>|null $file */
    public function uploadAvatar(?array $file): int
    {
        $userId = $this->userId();
        $profile = $this->getOwnProfile();
        $oldAvatarId = isset($profile['avatar_media_id']) ? (int) $profile['avatar_media_id'] : null;
        $prepared = $this->storage->prepareUpload($file, 'avatar');

        if ($prepared['media_type'] !== 'image' || $prepared['visibility'] !== 'public') {
            throw new RuntimeException('El avatar debe ser una imagen pública válida.');
        }

        $moved = false;

        try {
            $this->storage->movePreparedUpload($prepared);
            $moved = true;

            $this->pdo->beginTransaction();

            $mediaId = $this->media->createMedia([
                'owner_user_id' => $userId,
                'storage_key' => $prepared['storage_key'],
                'media_type' => 'image',
                'visibility' => 'public',
                'mime_type' => $prepared['mime_type'],
                'size_bytes' => $prepared['size_bytes'],
                'checksum' => $prepared['checksum'],
            ]);

            if (!$this->profiles->setAvatar($userId, $mediaId)) {
                $this->pdo->rollBack();
                throw new RuntimeException('No se pudo actualizar el avatar.');
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($moved) {
                $this->storage->deleteByStorageKey($prepared['storage_key']);
            }

            throw $exception;
        }

        if ($oldAvatarId !== null && $oldAvatarId > 0 && $oldAvatarId !== $mediaId) {
            $this->cleanupUnreferencedMedia($oldAvatarId);
        }

        return $mediaId;
    }

    public function removeAvatar(): bool
    {
        $profile = $this->getOwnProfile();
        $oldAvatarId = isset($profile['avatar_media_id']) ? (int) $profile['avatar_media_id'] : null;

        if ($oldAvatarId === null || $oldAvatarId <= 0) {
            return true;
        }

        $cleared = $this->profiles->clearAvatar($this->userId());

        if ($cleared) {
            $this->cleanupUnreferencedMedia($oldAvatarId);
        }

        return $cleared;
    }

    private function cleanupUnreferencedMedia(int $mediaId): void
    {
        $media = $this->media->findById($mediaId);

        if ($media === null || $this->media->countReferences($mediaId) > 0) {
            return;
        }

        if ($this->media->softDeleteMedia($mediaId)) {
            $this->storage->deleteByStorageKey((string) $media['storage_key']);
        }
    }

    private function userId(): int
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            throw new RuntimeException('forbidden');
        }

        return $userId;
    }
}
