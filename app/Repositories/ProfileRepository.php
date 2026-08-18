<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ProfileRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findByUserId(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.user_id, p.display_name, p.username, p.bio, p.avatar_media_id,
                    p.created_at, p.updated_at, p.deleted_at,
                    mf.storage_key AS avatar_storage_key, mf.mime_type AS avatar_mime_type,
                    mf.status AS avatar_status, mf.deleted_at AS avatar_deleted_at
             FROM profiles p
             LEFT JOIN media_files mf ON mf.id = p.avatar_media_id
             WHERE p.user_id = :user_id
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $profile = $statement->fetch();

        return is_array($profile) ? $this->normalize($profile) : null;
    }

    /** @return array<string, mixed>|null */
    public function findPublicCreatorByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.user_id, p.display_name, p.username, p.bio, mf.id AS avatar_media_id,
                    mf.mime_type AS avatar_mime_type
             FROM profiles p
             INNER JOIN users u ON u.id = p.user_id
             INNER JOIN creator_profiles cp ON cp.user_id = u.id
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             LEFT JOIN media_files mf ON mf.id = p.avatar_media_id
                AND mf.media_type = 'image'
                AND mf.visibility = 'public'
                AND mf.status = 'active'
                AND mf.deleted_at IS NULL
             WHERE p.username = :username
                AND p.deleted_at IS NULL
                AND u.status = 'active'
                AND u.deleted_at IS NULL
                AND cp.status = 'active'
                AND cp.deleted_at IS NULL
                AND r.name = 'creator'
             LIMIT 1"
        );
        $statement->execute(['username' => $username]);
        $profile = $statement->fetch();

        return is_array($profile) ? $this->normalize($profile) : null;
    }

    /** @return array<string, mixed>|null */
    public function findPublicCreatorByUserId(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.user_id, p.display_name, p.username, p.bio, mf.id AS avatar_media_id,
                    mf.mime_type AS avatar_mime_type
             FROM profiles p
             INNER JOIN users u ON u.id = p.user_id
             INNER JOIN creator_profiles cp ON cp.user_id = u.id
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             LEFT JOIN media_files mf ON mf.id = p.avatar_media_id
                AND mf.media_type = 'image'
                AND mf.visibility = 'public'
                AND mf.status = 'active'
                AND mf.deleted_at IS NULL
             WHERE p.user_id = :user_id
                AND p.deleted_at IS NULL
                AND u.status = 'active'
                AND u.deleted_at IS NULL
                AND cp.status = 'active'
                AND cp.deleted_at IS NULL
                AND r.name = 'creator'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);
        $profile = $statement->fetch();

        return is_array($profile) ? $this->normalize($profile) : null;
    }

    public function usernameExists(string $username, ?int $ignoreUserId = null): bool
    {
        $sql = 'SELECT 1 FROM profiles WHERE LOWER(username) = :username AND deleted_at IS NULL';
        $params = ['username' => $username];

        if ($ignoreUserId !== null) {
            $sql .= ' AND user_id <> :ignore_user_id';
            $params['ignore_user_id'] = $ignoreUserId;
        }

        $sql .= ' LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /** @param array{display_name: string, username: string, bio: string|null} $data */
    public function updateProfile(int $userId, array $data): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE profiles
             SET display_name = :display_name,
                 username = :username,
                 bio = :bio
             WHERE user_id = :user_id AND deleted_at IS NULL'
        );
        $statement->execute([
            'user_id' => $userId,
            'display_name' => $data['display_name'],
            'username' => $data['username'],
            'bio' => $data['bio'],
        ]);

        return $statement->rowCount() <= 1;
    }

    public function setAvatar(int $userId, int $mediaId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE profiles
             SET avatar_media_id = :avatar_media_id
             WHERE user_id = :user_id AND deleted_at IS NULL'
        );
        $statement->execute([
            'user_id' => $userId,
            'avatar_media_id' => $mediaId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function clearAvatar(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE profiles
             SET avatar_media_id = NULL
             WHERE user_id = :user_id AND deleted_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->rowCount() <= 1;
    }

    /** @param array<string, mixed> $profile @return array<string, mixed> */
    private function normalize(array $profile): array
    {
        foreach (['id', 'user_id', 'avatar_media_id'] as $key) {
            if (array_key_exists($key, $profile) && $profile[$key] !== null) {
                $profile[$key] = (int) $profile[$key];
            }
        }

        return $profile;
    }
}
