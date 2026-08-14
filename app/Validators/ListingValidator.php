<?php

declare(strict_types=1);

namespace App\Validators;

use App\Repositories\CategoryRepository;

final class ListingValidator
{
    private const LISTING_TYPES = ['physical_product', 'digital_content', 'service', 'bundle'];
    private const VISIBILITIES = ['public', 'private', 'unlisted'];

    public function __construct(
        private readonly CategoryRepository $categories
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, data: array{title: string, description: string|null, listing_type: string, price: string, currency: string, visibility: string, category_ids: list<int>}, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $listingType = (string) ($input['listing_type'] ?? '');
        $price = trim(str_replace(',', '.', (string) ($input['price'] ?? '')));
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        $visibility = (string) ($input['visibility'] ?? '');
        $categoryIds = $this->normalizeCategoryIds($input['categories'] ?? []);

        $errors = [];

        if ($title === '') {
            $errors['title'] = 'El título es obligatorio.';
        } elseif ($this->length($title) > 180) {
            $errors['title'] = 'El título no puede superar 180 caracteres.';
        }

        if ($description !== '' && $this->length($description) > 5000) {
            $errors['description'] = 'La descripción no puede superar 5000 caracteres.';
        }

        if (!in_array($listingType, self::LISTING_TYPES, true)) {
            $errors['listing_type'] = 'El tipo de publicación no es válido.';
        }

        if (!$this->validPrice($price)) {
            $errors['price'] = 'El precio debe ser un número válido mayor o igual a 0.';
        }

        if ($currency !== 'EUR') {
            $errors['currency'] = 'La moneda debe ser EUR.';
        }

        if (!in_array($visibility, self::VISIBILITIES, true)) {
            $errors['visibility'] = 'La visibilidad no es válida.';
        }

        if ($categoryIds === []) {
            $errors['categories'] = 'Selecciona al menos una categoría.';
        } else {
            $foundCategories = $this->categories->findByIds($categoryIds);
            $foundIds = array_map(static fn (array $category): int => $category['id'], $foundCategories);

            if (count($foundIds) !== count($categoryIds)) {
                $errors['categories'] = 'Selecciona solo categorías activas y válidas.';
            }
        }

        return [
            'valid' => $errors === [],
            'data' => [
                'title' => $title,
                'description' => $description === '' ? null : $description,
                'listing_type' => $listingType,
                'price' => $this->normalizePrice($price),
                'currency' => $currency,
                'visibility' => $visibility,
                'category_ids' => $categoryIds,
            ],
            'errors' => $errors,
        ];
    }

    /** @return list<int> */
    private function normalizeCategoryIds(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $ids = [];

        foreach ($values as $rawId) {
            $rawId = trim((string) $rawId);

            if ($rawId !== '' && ctype_digit($rawId)) {
                $ids[] = (int) $rawId;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    private function validPrice(string $price): bool
    {
        if (preg_match('/\A\d{1,10}(?:\.\d{1,2})?\z/', $price) !== 1) {
            return false;
        }

        return (float) $price <= 9999999999.99;
    }

    private function normalizePrice(string $price): string
    {
        return number_format((float) $price, 2, '.', '');
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
