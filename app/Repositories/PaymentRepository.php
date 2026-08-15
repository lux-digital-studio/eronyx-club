<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PaymentRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function createPending(int $orderId, string $provider, string $externalId, string $amount, string $currency): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO payments (
                order_id, provider, external_id, amount, currency, status, paid_at, created_at, updated_at
             ) VALUES (
                :order_id, :provider, :external_id, :amount, :currency, 'pending', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )"
        );
        $statement->execute([
            'order_id' => $orderId,
            'provider' => $provider,
            'external_id' => $externalId,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findByOrder(int $orderId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, order_id, provider, external_id, amount, currency, status, paid_at, created_at, updated_at
             FROM payments
             WHERE order_id = :order_id
             ORDER BY id ASC
             LIMIT 1'
        );
        $statement->execute(['order_id' => $orderId]);
        $payment = $statement->fetch();

        return is_array($payment) ? $this->normalize($payment) : null;
    }

    /** @return array<string, mixed>|null */
    public function findPendingByOrder(int $orderId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, order_id, provider, external_id, amount, currency, status, paid_at, created_at, updated_at
             FROM payments
             WHERE order_id = :order_id AND status = 'pending'
             ORDER BY id ASC
             LIMIT 1"
        );
        $statement->execute(['order_id' => $orderId]);
        $payment = $statement->fetch();

        return is_array($payment) ? $this->normalize($payment) : null;
    }

    public function markPaid(int $id): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE payments
             SET status = 'paid', paid_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'pending'"
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function markFailed(int $id): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE payments
             SET status = 'failed', updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'pending'"
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    /** @param array<string, mixed> $payment @return array<string, mixed> */
    private function normalize(array $payment): array
    {
        foreach (['id', 'order_id'] as $key) {
            $payment[$key] = (int) $payment[$key];
        }

        $payment['amount'] = $this->formatDecimal((string) $payment['amount']);

        return $payment;
    }

    private function formatDecimal(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');

        return $whole . '.' . str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
