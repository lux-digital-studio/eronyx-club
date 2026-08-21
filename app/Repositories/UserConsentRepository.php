<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserConsentRepository
{
    public const TYPES = [
        'terms',
        'privacy',
        'creator_rules',
        'content_policy',
        'age_declaration',
    ];

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function record(int $userId, string $consentType, string $documentVersion): bool
    {
        if ($userId <= 0 || !$this->validType($consentType) || $documentVersion === '') {
            return false;
        }

        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO user_consents (user_id, consent_type, document_version, accepted_at, created_at)
             VALUES (:user_id, :consent_type, :document_version, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'user_id' => $userId,
            'consent_type' => $consentType,
            'document_version' => substr($documentVersion, 0, 40),
        ]);

        return $statement->rowCount() === 1;
    }

    public function hasAccepted(int $userId, string $consentType, string $documentVersion): bool
    {
        if (!$this->validType($consentType)) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM user_consents
             WHERE user_id = :user_id
                AND consent_type = :consent_type
                AND document_version = :document_version
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'consent_type' => $consentType,
            'document_version' => substr($documentVersion, 0, 40),
        ]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<array{consent_type: string, document_version: string, accepted_at: string}> */
    public function findForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT consent_type, document_version, accepted_at
             FROM user_consents
             WHERE user_id = :user_id
             ORDER BY accepted_at DESC, id DESC'
        );
        $statement->execute(['user_id' => $userId]);
        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'consent_type' => (string) $row['consent_type'],
                'document_version' => (string) $row['document_version'],
                'accepted_at' => (string) $row['accepted_at'],
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, string> $required type => version
     */
    public function hasCurrentRequiredConsents(int $userId, array $required): bool
    {
        foreach ($required as $type => $version) {
            if (!$this->hasAccepted($userId, $type, $version)) {
                return false;
            }
        }

        return true;
    }

    private function validType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }
}
