<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class OrderRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function createOrder(int $buyerUserId, string $subtotalAmount, string $totalAmount, string $currency): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO orders (buyer_user_id, status, subtotal_amount, total_amount, currency, created_at, updated_at)
             VALUES (:buyer_user_id, 'pending', :subtotal_amount, :total_amount, :currency, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $statement->execute([
            'buyer_user_id' => $buyerUserId,
            'subtotal_amount' => $subtotalAmount,
            'total_amount' => $totalAmount,
            'currency' => $currency,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, buyer_user_id, status, subtotal_amount, total_amount, currency, created_at, updated_at, deleted_at
             FROM orders
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $order = $statement->fetch();

        return is_array($order) ? $this->normalize($order) : null;
    }

    /** @return array<string, mixed>|null */
    public function findOwnedById(int $id, int $buyerUserId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, buyer_user_id, status, subtotal_amount, total_amount, currency, created_at, updated_at, deleted_at
             FROM orders
             WHERE id = :id AND buyer_user_id = :buyer_user_id AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'id' => $id,
            'buyer_user_id' => $buyerUserId,
        ]);
        $order = $statement->fetch();

        return is_array($order) ? $this->normalize($order) : null;
    }

    /** @return list<array<string, mixed>> */
    public function findAllByBuyer(int $buyerUserId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, buyer_user_id, status, subtotal_amount, total_amount, currency, created_at, updated_at, deleted_at
             FROM orders
             WHERE buyer_user_id = :buyer_user_id AND deleted_at IS NULL
             ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['buyer_user_id' => $buyerUserId]);

        return array_map(fn (array $order): array => $this->normalize($order), $statement->fetchAll());
    }

    public function markPaid(int $id): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE orders
             SET status = 'paid', updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'pending' AND deleted_at IS NULL"
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function markCompleted(int $id): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE orders
             SET status = 'completed', updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status IN ('pending', 'paid') AND deleted_at IS NULL"
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function normalize(array $order): array
    {
        foreach (['id', 'buyer_user_id'] as $key) {
            $order[$key] = (int) $order[$key];
        }

        foreach (['subtotal_amount', 'total_amount'] as $key) {
            $order[$key] = $this->formatDecimal((string) $order[$key]);
        }

        return $order;
    }

    private function formatDecimal(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');

        return $whole . '.' . str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
