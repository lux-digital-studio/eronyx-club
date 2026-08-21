<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Interfaces\AgeVerificationProviderInterface;

/**
 * Local/test provider. No network, no documents, no biometrics.
 * Future production webhook would call AgeVerificationService::markProviderResult()
 * after verifying the provider signature; that public endpoint is not created yet.
 */
final class TestAgeVerificationProvider implements AgeVerificationProviderInterface
{
    /** @var array<string, string> */
    private static array $sessions = [];

    public function createSession(int $userId, int $ttlSeconds): array
    {
        $reference = 'test_' . $userId . '_' . bin2hex(random_bytes(8));
        self::$sessions[$reference] = 'pending';
        $expires = (new \DateTimeImmutable('+' . max(60, $ttlSeconds) . ' seconds'))->format('Y-m-d H:i:s');

        return [
            'provider' => 'test',
            'provider_reference' => $reference,
            'provider_status' => 'pending',
            'expires_at' => $expires,
        ];
    }

    public function getStatus(string $providerReference): string
    {
        return self::$sessions[$providerReference] ?? 'pending';
    }

    public function cancelSession(string $providerReference): void
    {
        self::$sessions[$providerReference] = 'cancelled';
    }

    public static function simulate(string $providerReference, string $status): void
    {
        if (!in_array($status, ['pending', 'verified', 'rejected', 'expired', 'cancelled'], true)) {
            return;
        }

        self::$sessions[$providerReference] = $status;
    }

    public static function reset(): void
    {
        self::$sessions = [];
    }
}
