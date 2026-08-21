<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\ListingRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderItemRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\UserRepository;
use RuntimeException;
use Throwable;

final class CommerceService
{
    private \PDO $pdo;
    private OrderRepository $orders;
    private OrderItemRepository $items;
    private PaymentRepository $payments;
    private ListingRepository $listings;
    private PrivateContentAccessService $privateAccess;
    private NotificationService $notifications;
    private TransactionalMailService $mail;

    public function __construct(
        ?\PDO $pdo = null,
        ?OrderRepository $orders = null,
        ?OrderItemRepository $items = null,
        ?PaymentRepository $payments = null,
        ?ListingRepository $listings = null,
        ?PrivateContentAccessService $privateAccess = null,
        ?NotificationService $notifications = null,
        ?TransactionalMailService $mail = null
    ) {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->orders = $orders ?? new OrderRepository($this->pdo);
        $this->items = $items ?? new OrderItemRepository($this->pdo);
        $this->payments = $payments ?? new PaymentRepository($this->pdo);
        $this->listings = $listings ?? new ListingRepository($this->pdo);
        $this->privateAccess = $privateAccess ?? new PrivateContentAccessService(null, $this->listings, null, $this->pdo);
        $this->notifications = $notifications ?? new NotificationService(
            new NotificationRepository($this->pdo),
            new UserRepository($this->pdo)
        );
        $this->mail = $mail ?? new TransactionalMailService(null, null, new UserRepository($this->pdo), $this->pdo);
    }

    /** @return array<string, mixed> */
    public function checkoutPreview(int $buyerUserId, int $listingId): array
    {
        return $this->buyableListing($buyerUserId, $listingId);
    }

    /** @return array{order_id: int, payment_id: int} */
    public function createCheckout(int $buyerUserId, int $listingId): array
    {
        $listing = $this->buyableListing($buyerUserId, $listingId);
        $amount = (string) $listing['price'];
        $currency = (string) $listing['currency'];

        try {
            $this->pdo->beginTransaction();

            $orderId = $this->orders->createOrder($buyerUserId, $amount, $amount, $currency);
            $this->items->create(
                $orderId,
                (int) $listing['id'],
                (int) $listing['owner_user_id'],
                (string) $listing['title'],
                $amount,
                1,
                $amount,
                $currency
            );
            $paymentId = $this->payments->createPending(
                $orderId,
                'test',
                'test_' . bin2hex(random_bytes(16)),
                $amount,
                $currency
            );

            $this->pdo->commit();

            return [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function confirmTestPayment(int $orderId, int $actorUserId): bool
    {
        $order = $this->orders->findById($orderId);

        if ($order === null) {
            throw new RuntimeException('not_found');
        }

        if ((int) $order['buyer_user_id'] !== $actorUserId) {
            throw new RuntimeException('forbidden');
        }

        $payment = $this->payments->findPendingByOrder($orderId);

        if ($payment === null) {
            return false;
        }

        try {
            $this->pdo->beginTransaction();

            if (!$this->payments->markPaid((int) $payment['id'])) {
                $this->pdo->rollBack();

                return false;
            }

            $items = $this->items->findByOrder($orderId);
            $allFulfilled = $items !== [];

            foreach ($items as $item) {
                $listing = $this->listings->findById((int) $item['listing_id']);

                if ($listing !== null && in_array($listing['listing_type'], ['digital_content', 'bundle'], true)) {
                    $this->privateAccess->grantAccess($actorUserId, (int) $listing['id'], null, 'test', null);
                    $this->items->markFulfilled((int) $item['id']);
                    continue;
                }

                $allFulfilled = false;
            }

            if ($allFulfilled) {
                $this->orders->markCompleted($orderId);
                $this->notifications->notify(
                    (int) $order['buyer_user_id'],
                    'order_completed',
                    'Tu pedido se ha completado',
                    'El pago de prueba se ha confirmado y tu pedido está listo.',
                    null,
                    'order',
                    $orderId,
                    '/account/orders/' . $orderId,
                    'order:' . $orderId . ':completed'
                );
            } else {
                $this->orders->markPaid($orderId);
                $this->notifications->notify(
                    (int) $order['buyer_user_id'],
                    'order_paid',
                    'Hemos recibido el pago de tu pedido',
                    'El pago de prueba se ha confirmado. El pedido sigue en proceso.',
                    null,
                    'order',
                    $orderId,
                    '/account/orders/' . $orderId,
                    'order:' . $orderId . ':paid'
                );
            }

            $this->pdo->commit();

            $orderForMail = $this->orders->findById($orderId) ?? $order;
            $itemsForMail = $this->items->findByOrder($orderId);

            if ($allFulfilled) {
                $this->mail->sendOrderCompleted($orderForMail, $itemsForMail);
            } else {
                $this->mail->sendOrderPaid($orderForMail, $itemsForMail);
            }

            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function buyableListing(int $buyerUserId, int $listingId): array
    {
        $listing = $this->listings->findById($listingId);

        if ($listing === null || !$this->isBuyable($listing)) {
            throw new RuntimeException('not_found');
        }

        if ((int) $listing['owner_user_id'] === $buyerUserId) {
            throw new RuntimeException('forbidden');
        }

        if (
            in_array($listing['listing_type'], ['digital_content', 'bundle'], true)
            && $this->privateAccess->canAccessListingPrivateContent($buyerUserId, $listingId)
        ) {
            throw new RuntimeException('forbidden');
        }

        return $listing;
    }

    /** @param array<string, mixed> $listing */
    private function isBuyable(array $listing): bool
    {
        return $listing['status'] === 'published'
            && $listing['published_at'] !== null
            && in_array($listing['visibility'], ['public', 'unlisted'], true)
            && $listing['currency'] === 'EUR'
            && preg_match('/\A\d+(?:\.\d{2})\z/', (string) $listing['price']) === 1;
    }
}
