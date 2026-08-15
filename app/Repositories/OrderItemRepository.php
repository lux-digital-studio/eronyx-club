<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class OrderItemRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function create(
        int $orderId,
        int $listingId,
        int $sellerUserId,
        string $titleSnapshot,
        string $unitPrice,
        int $quantity,
        string $totalAmount,
        string $currency
    ): int {
        $statement = $this->pdo->prepare(
            "INSERT INTO order_items (
                order_id, listing_id, seller_user_id, title_snapshot, unit_price,
                quantity, total_amount, currency, status, created_at, updated_at
             ) VALUES (
                :order_id, :listing_id, :seller_user_id, :title_snapshot, :unit_price,
                :quantity, :total_amount, :currency, 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )"
        );
        $statement->execute([
            'order_id' => $orderId,
            'listing_id' => $listingId,
            'seller_user_id' => $sellerUserId,
            'title_snapshot' => $titleSnapshot,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'total_amount' => $totalAmount,
            'currency' => $currency,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function findByOrder(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, order_id, listing_id, seller_user_id, title_snapshot, unit_price,
                    quantity, total_amount, currency, status, created_at, updated_at
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY id ASC'
        );
        $statement->execute(['order_id' => $orderId]);

        return array_map(fn (array $item): array => $this->normalize($item), $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, order_id, listing_id, seller_user_id, title_snapshot, unit_price,
                    quantity, total_amount, currency, status, created_at, updated_at
             FROM order_items
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $item = $statement->fetch();

        return is_array($item) ? $this->normalize($item) : null;
    }

    public function markFulfilled(int $id): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE order_items
             SET status = 'fulfilled', updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'pending'"
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function normalize(array $item): array
    {
        foreach (['id', 'order_id', 'listing_id', 'seller_user_id', 'quantity'] as $key) {
            $item[$key] = (int) $item[$key];
        }

        foreach (['unit_price', 'total_amount'] as $key) {
            $item[$key] = $this->formatDecimal((string) $item[$key]);
        }

        return $item;
    }

    private function formatDecimal(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');

        return $whole . '.' . str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
