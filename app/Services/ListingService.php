<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\CategoryRepository;
use App\Repositories\ListingRepository;
use App\Repositories\MediaRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use App\Validators\ListingValidator;
use RuntimeException;
use Throwable;

final class ListingService
{
    private \PDO $pdo;
    private ListingRepository $listings;
    private CategoryRepository $categories;
    private MediaRepository $media;
    private NotificationService $notifications;
    private TransactionalMailService $mail;

    public function __construct(
        private readonly Auth $auth,
        ?\PDO $pdo = null,
        ?ListingRepository $listings = null,
        ?CategoryRepository $categories = null,
        ?MediaRepository $media = null,
        ?NotificationService $notifications = null,
        ?TransactionalMailService $mail = null
    ) {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->listings = $listings ?? new ListingRepository($this->pdo);
        $this->categories = $categories ?? new CategoryRepository($this->pdo);
        $this->media = $media ?? new MediaRepository($this->pdo);
        $this->notifications = $notifications ?? new NotificationService(
            new NotificationRepository($this->pdo),
            new UserRepository($this->pdo)
        );
        $this->mail = $mail ?? new TransactionalMailService(null, null, new UserRepository($this->pdo), $this->pdo);
    }

    /** @param array{title: string, description: string|null, listing_type: string, price: string, currency: string, visibility: string, category_ids: list<int>} $data */
    public function create(array $data): int
    {
        $ownerUserId = $this->ownerUserId();
        $slug = $this->uniqueSlug($data['title']);

        try {
            $this->pdo->beginTransaction();

            $listingId = $this->listings->create([
                'owner_user_id' => $ownerUserId,
                'title' => $data['title'],
                'slug' => $slug,
                'description' => $data['description'],
                'listing_type' => $data['listing_type'],
                'price' => $data['price'],
                'currency' => $data['currency'],
                'visibility' => $data['visibility'],
            ]);
            $this->listings->replaceCategories($listingId, $data['category_ids']);

            $this->pdo->commit();

            return $listingId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @param array{title: string, description: string|null, listing_type: string, price: string, currency: string, visibility: string, category_ids: list<int>} $data */
    public function updateDraft(int $listingId, array $data): bool
    {
        $ownerUserId = $this->ownerUserId();
        $slug = $this->uniqueSlug($data['title'], $listingId);

        try {
            $this->pdo->beginTransaction();

            $updated = $this->listings->updateEditableDraft($listingId, $ownerUserId, [
                'title' => $data['title'],
                'slug' => $slug,
                'description' => $data['description'],
                'listing_type' => $data['listing_type'],
                'price' => $data['price'],
                'currency' => $data['currency'],
                'visibility' => $data['visibility'],
            ]);

            if (!$updated) {
                $this->pdo->rollBack();

                return false;
            }

            $this->listings->replaceCategories($listingId, $data['category_ids']);
            $this->pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function submitForReview(int $listingId): bool
    {
        $ownerUserId = $this->ownerUserId();
        $listing = $this->listings->findOwnedById($listingId, $ownerUserId);

        if ($listing === null || $listing['status'] !== 'draft') {
            return false;
        }

        $categoryIds = $this->listings->findCategoryIdsForListing($listingId);
        $validation = (new ListingValidator($this->categories))->validate([
            'title' => $listing['title'],
            'description' => $listing['description'] ?? '',
            'listing_type' => $listing['listing_type'],
            'price' => $listing['price'],
            'currency' => $listing['currency'],
            'visibility' => $listing['visibility'],
            'categories' => $categoryIds,
        ]);

        if (!$validation['valid']) {
            return false;
        }

        if (!$this->media->hasValidCoverForListing($listingId)) {
            throw new RuntimeException('cover_required');
        }

        return $this->listings->markPendingReview($listingId, $ownerUserId);
    }

    public function approve(int $listingId): bool
    {
        $listing = $this->listings->findById($listingId);
        $updated = $this->listings->approvePending($listingId);

        if ($updated && $listing !== null) {
            $this->notifyListingOwner(
                $listing,
                'listing_approved',
                'Tu publicación ha sido aprobada',
                'Tu publicación ya está disponible según su visibilidad.',
                'approved'
            );
            $this->mail->sendListingApproved($listing);
        }

        return $updated;
    }

    public function reject(int $listingId): bool
    {
        $listing = $this->listings->findById($listingId);
        $updated = $this->listings->rejectPending($listingId);

        if ($updated && $listing !== null) {
            $this->notifyListingOwner(
                $listing,
                'listing_rejected',
                'Tu publicación ha sido rechazada',
                'Revisa el contenido y vuelve a enviarla cuando esté lista.',
                'rejected'
            );
            $this->mail->sendListingRejected($listing);
        }

        return $updated;
    }

    /** @param array<string, mixed> $listing */
    private function notifyListingOwner(
        array $listing,
        string $type,
        string $title,
        string $body,
        string $dedupeSuffix
    ): void {
        $listingId = (int) $listing['id'];

        $this->notifications->notify(
            (int) $listing['owner_user_id'],
            $type,
            $title,
            $body,
            null,
            'listing',
            $listingId,
            '/creator/listings/' . $listingId,
            'listing:' . $listingId . ':' . $dedupeSuffix . ':' . $this->cycleKey($listing['updated_at'] ?? $listing['created_at'] ?? null)
        );
    }

    private function cycleKey(mixed $timestamp): string
    {
        $digits = preg_replace('/\D+/', '', (string) $timestamp);

        return is_string($digits) && $digits !== '' ? $digits : '0';
    }

    private function uniqueSlug(string $title, ?int $ignoreListingId = null): string
    {
        $base = $this->slugify($title);
        $slug = $base;
        $counter = 2;

        while ($this->listings->slugExists($slug, $ignoreListingId)) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function slugify(string $value): string
    {
        $value = trim($value);
        $value = strtr($value, [
            'Á' => 'A',
            'À' => 'A',
            'Â' => 'A',
            'Ä' => 'A',
            'Ã' => 'A',
            'Å' => 'A',
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'ã' => 'a',
            'å' => 'a',
            'É' => 'E',
            'È' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'Í' => 'I',
            'Ì' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'Ó' => 'O',
            'Ò' => 'O',
            'Ô' => 'O',
            'Ö' => 'O',
            'Õ' => 'O',
            'ó' => 'o',
            'ò' => 'o',
            'ô' => 'o',
            'ö' => 'o',
            'õ' => 'o',
            'Ú' => 'U',
            'Ù' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'Ñ' => 'N',
            'ñ' => 'n',
            'Ç' => 'C',
            'ç' => 'c',
        ]);

        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            $value = is_string($converted) ? $converted : $value;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value === '' ? 'listing' : substr($value, 0, 180);
    }

    private function ownerUserId(): int
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            throw new RuntimeException('Authenticated user required.');
        }

        return $userId;
    }
}
