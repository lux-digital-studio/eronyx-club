<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\UserRepository;

final class TransactionalMailService
{
    private MailService $mail;
    private EmailRenderer $renderer;
    private UserRepository $users;

    public function __construct(
        ?MailService $mail = null,
        ?EmailRenderer $renderer = null,
        ?UserRepository $users = null,
        ?\PDO $pdo = null
    ) {
        $pdo ??= $users === null ? (new Database())->connection() : null;
        $this->mail = $mail ?? new MailService();
        $this->renderer = $renderer ?? new EmailRenderer();
        $this->users = $users ?? new UserRepository($pdo ?? (new Database())->connection());
    }

    public function sendPasswordReset(int $userId, string $resetUrl): bool
    {
        return $this->sendTemplate($userId, 'password-reset', 'password_reset', [
            'resetUrl' => $resetUrl,
            'expiresMinutes' => 30,
        ], true);
    }

    public function sendPasswordChanged(int $userId): bool
    {
        return $this->sendTemplate($userId, 'password-changed', 'password_changed', [], true);
    }

    public function sendPasswordResetCompleted(int $userId): bool
    {
        return $this->sendTemplate($userId, 'password-reset-completed', 'password_reset_completed', [], true);
    }

    public function sendMfaEnabled(int $userId): bool
    {
        return $this->sendTemplate($userId, 'mfa-enabled', 'mfa_enabled', [], true);
    }

    public function sendMfaDisabled(int $userId): bool
    {
        return $this->sendTemplate($userId, 'mfa-disabled', 'mfa_disabled', [], true);
    }

    public function sendEmailVerification(int $userId, string $verificationUrl): bool
    {
        return $this->sendTemplate($userId, 'email-verification', 'email_verification', [
            'verificationUrl' => $verificationUrl,
            'expiresHours' => 24,
        ], true);
    }

    public function sendCreatorApproved(int $userId): bool
    {
        return $this->sendTemplate($userId, 'creator-application-approved', 'creator_application_approved', [
            'actionUrl' => $this->renderer->url('/creator'),
        ]);
    }

    public function sendCreatorRejected(int $userId): bool
    {
        return $this->sendTemplate($userId, 'creator-application-rejected', 'creator_application_rejected', [
            'actionUrl' => $this->renderer->url('/account/creator/status'),
        ]);
    }

    /** @param array<string, mixed> $listing */
    public function sendListingApproved(array $listing): bool
    {
        $slug = (string) ($listing['slug'] ?? '');

        return $this->sendTemplate((int) $listing['owner_user_id'], 'listing-approved', 'listing_approved', [
            'listingTitle' => (string) ($listing['title'] ?? ''),
            'actionUrl' => $this->renderer->url('/marketplace/' . ltrim($slug, '/')),
        ]);
    }

    /** @param array<string, mixed> $listing */
    public function sendListingRejected(array $listing): bool
    {
        $listingId = (int) ($listing['id'] ?? 0);

        return $this->sendTemplate((int) $listing['owner_user_id'], 'listing-rejected', 'listing_rejected', [
            'listingTitle' => (string) ($listing['title'] ?? ''),
            'actionUrl' => $this->renderer->url('/creator/listings/' . $listingId),
        ]);
    }

    /** @param array<string, mixed> $listing */
    public function sendListingSuspended(array $listing): bool
    {
        $listingId = (int) ($listing['id'] ?? 0);

        return $this->sendTemplate((int) $listing['owner_user_id'], 'listing-suspended', 'listing_suspended', [
            'listingTitle' => (string) ($listing['title'] ?? ''),
            'actionUrl' => $this->renderer->url('/creator/listings/' . $listingId),
        ], true);
    }

    /** @param array<string, mixed> $listing */
    public function sendListingRestored(array $listing): bool
    {
        $listingId = (int) ($listing['id'] ?? 0);

        return $this->sendTemplate((int) $listing['owner_user_id'], 'listing-restored', 'listing_restored', [
            'listingTitle' => (string) ($listing['title'] ?? ''),
            'actionUrl' => $this->renderer->url('/creator/listings/' . $listingId),
        ]);
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $items
     */
    public function sendOrderPaid(array $order, array $items): bool
    {
        return $this->sendOrderTemplate($order, $items, 'order-paid', 'order_paid', 'paid');
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $items
     */
    public function sendOrderCompleted(array $order, array $items): bool
    {
        return $this->sendOrderTemplate($order, $items, 'order-completed', 'order_completed', 'completed');
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $items
     */
    private function sendOrderTemplate(array $order, array $items, string $template, string $type, string $status): bool
    {
        $titles = [];
        foreach ($items as $item) {
            $titles[] = (string) ($item['title_snapshot'] ?? '');
        }

        return $this->sendTemplate((int) $order['buyer_user_id'], $template, $type, [
            'orderId' => (int) ($order['id'] ?? 0),
            'listingTitle' => $titles[0] ?? '',
            'totalAmount' => (string) ($order['total_amount'] ?? ''),
            'currency' => (string) ($order['currency'] ?? 'EUR'),
            'status' => $status,
            'actionUrl' => $this->renderer->url('/account/orders/' . (int) ($order['id'] ?? 0)),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendTemplate(int $userId, string $template, string $type, array $data, bool $allowSuspended = false): bool
    {
        $recipient = $this->users->findMailRecipient($userId);

        if ($recipient === null || $recipient['deleted_at'] !== null) {
            return false;
        }

        if (!$allowSuspended && $recipient['status'] !== 'active') {
            return false;
        }

        $data['displayName'] = $recipient['display_name'] !== '' ? $recipient['display_name'] : $recipient['username'];
        $rendered = $this->renderer->render($template, $data);

        return $this->mail->send(
            $recipient['email'],
            EmailRenderer::plain($data['displayName']),
            $rendered['subject'],
            $rendered['html'],
            $rendered['text'],
            ['type' => $type, 'user_id' => $userId]
        );
    }
}
