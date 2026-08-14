<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\CategoryRepository;
use App\Repositories\ListingRepository;
use RuntimeException;
use Throwable;

final class ListingService
{
    private \PDO $pdo;
    private ListingRepository $listings;

    public function __construct(
        private readonly Auth $auth,
        ?\PDO $pdo = null,
        ?ListingRepository $listings = null,
        private readonly ?CategoryRepository $categories = null
    ) {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->listings = $listings ?? new ListingRepository($this->pdo);
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

            $updated = $this->listings->updateDraft($listingId, $ownerUserId, [
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
