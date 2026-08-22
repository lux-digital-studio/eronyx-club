<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Interfaces\AgeVerificationProviderInterface;
use RuntimeException;

final class AgeVerificationProviderFactory
{
    /** @param array<string, mixed> $config */
    public static function make(array $config): ?AgeVerificationProviderInterface
    {
        $mode = (string) ($config['mode'] ?? 'manual_review');

        if ($mode !== 'provider') {
            return null;
        }

        $provider = strtolower(trim((string) ($config['provider'] ?? '')));

        if ($provider === '') {
            throw new RuntimeException('provider_not_configured');
        }

        if ($provider === 'test') {
            $env = \App\Core\EnvironmentValidator::currentEnv();

            if (!\App\Core\EnvironmentValidator::allowsTestVerificationProvider($env)) {
                throw new RuntimeException('provider_not_configured');
            }

            return new TestAgeVerificationProvider();
        }

        throw new RuntimeException('provider_not_configured');
    }
}
