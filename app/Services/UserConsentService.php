<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\UserConsentRepository;

final class UserConsentService
{
    private \PDO $pdo;
    private UserConsentRepository $consents;

    public function __construct(
        ?\PDO $pdo = null,
        ?UserConsentRepository $consents = null
    ) {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->consents = $consents ?? new UserConsentRepository($this->pdo);
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 2) . '/config/legal.php';

        return $config;
    }

    public function version(string $type): string
    {
        $config = $this->config();

        return match ($type) {
            'terms' => (string) $config['terms_version'],
            'privacy' => (string) $config['privacy_version'],
            'cookies' => (string) $config['cookies_version'],
            'content_policy' => (string) $config['content_policy_version'],
            'creator_rules' => (string) $config['creator_rules_version'],
            'age_declaration', 'age_policy' => (string) $config['age_policy_version'],
            'reporting_policy' => (string) $config['reporting_policy_version'],
            default => '',
        };
    }

    public function record(int $userId, string $consentType, ?string $postedVersion = null): bool
    {
        unset($postedVersion);

        return $this->consents->record($userId, $consentType, $this->version($consentType));
    }

    public function recordRegisterConsents(int $userId): void
    {
        $this->record($userId, 'terms');
        $this->record($userId, 'privacy');
        $this->record($userId, 'age_declaration');
    }

    public function recordCreatorConsents(int $userId): void
    {
        $this->record($userId, 'creator_rules');
        $this->record($userId, 'content_policy');
        $this->record($userId, 'age_declaration');
    }

    public function hasAccepted(int $userId, string $consentType, ?string $version = null): bool
    {
        return $this->consents->hasAccepted($userId, $consentType, $version ?? $this->version($consentType));
    }

    /** @return list<array{consent_type: string, document_version: string, accepted_at: string}> */
    public function findForUser(int $userId): array
    {
        return $this->consents->findForUser($userId);
    }

    public function hasCurrentRegisterConsents(int $userId): bool
    {
        return $this->consents->hasCurrentRequiredConsents($userId, [
            'terms' => $this->version('terms'),
            'privacy' => $this->version('privacy'),
            'age_declaration' => $this->version('age_declaration'),
        ]);
    }

    public function hasCurrentCreatorConsents(int $userId): bool
    {
        return $this->consents->hasCurrentRequiredConsents($userId, [
            'creator_rules' => $this->version('creator_rules'),
            'content_policy' => $this->version('content_policy'),
            'age_declaration' => $this->version('age_declaration'),
        ]);
    }
}
