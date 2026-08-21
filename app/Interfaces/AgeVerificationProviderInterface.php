<?php

declare(strict_types=1);

namespace App\Interfaces;

interface AgeVerificationProviderInterface
{
    /**
     * @return array{
     *   provider: string,
     *   provider_reference: string,
     *   provider_status: string,
     *   expires_at: string|null
     * }
     */
    public function createSession(int $userId, int $ttlSeconds): array;

    public function getStatus(string $providerReference): string;

    public function cancelSession(string $providerReference): void;
}
