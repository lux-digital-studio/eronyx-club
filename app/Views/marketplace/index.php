<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$selected = static fn (?string $actual, string $expected): string => ($actual ?? '') === $expected ? ' selected' : '';
$pageUrl = static function (array $query, int $page) use ($indexUrl): string {
    $params = $query;
    unset($params['page']);

    if ($page > 1) {
        $params['page'] = $page;
    }

    $qs = http_build_query($params);

    return $qs === '' ? $indexUrl : $indexUrl . '?' . $qs;
};

$typeLabels = [
    'physical_product' => \App\Core\Layout::listingTypeLabel('physical_product'),
    'digital_content' => \App\Core\Layout::listingTypeLabel('digital_content'),
    'service' => \App\Core\Layout::listingTypeLabel('service'),
    'bundle' => \App\Core\Layout::listingTypeLabel('bundle'),
];

$categoryName = null;
foreach ($categories as $category) {
    if (($filters['category'] ?? null) === $category['slug']) {
        $categoryName = $category['name'];
        break;
    }
}

$chips = [];
if (($filters['q'] ?? null) !== null && $filters['q'] !== '') {
    $chips[] = (string) $filters['q'];
}
if ($categoryName !== null) {
    $chips[] = $categoryName;
}
if (($filters['type'] ?? null) !== null && $filters['type'] !== '') {
    $chips[] = $typeLabels[$filters['type']] ?? (string) $filters['type'];
}
if (($filters['min_price'] ?? null) !== null && ($filters['max_price'] ?? null) !== null) {
    $chips[] = \App\Core\Layout::formatPrice($filters['min_price']) . ' – ' . \App\Core\Layout::formatPrice($filters['max_price']);
} elseif (($filters['min_price'] ?? null) !== null) {
    $chips[] = 'Desde ' . \App\Core\Layout::formatPrice($filters['min_price']);
} elseif (($filters['max_price'] ?? null) !== null) {
    $chips[] = 'Hasta ' . \App\Core\Layout::formatPrice($filters['max_price']);
}
if (($filters['creator'] ?? null) !== null && $filters['creator'] !== '') {
    $chips[] = '@' . (string) $filters['creator'];
}

$hasActiveFilters = $chips !== [];
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Marketplace</h1>
        <p class="page-subtitle">Explora publicaciones de creators de ERONYX.</p>
    </header>

    <form class="filter-panel" method="get" action="<?= $e($indexUrl) ?>">
        <div class="filter-grid">
            <div class="form-group filter-q">
                <label for="q">Buscar</label>
                <input id="q" type="search" name="q" value="<?= $e($filters['q'] ?? '') ?>" maxlength="100" placeholder="Buscar publicaciones...">
            </div>

            <div class="form-group">
                <label for="category">Categoría</label>
                <select id="category" name="category">
                    <option value="">Todas</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $e($category['slug']) ?>"<?= $selected($filters['category'] ?? null, $category['slug']) ?>>
                            <?= $e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="type">Tipo</label>
                <select id="type" name="type">
                    <option value="">Todos</option>
                    <?php foreach ($typeLabels as $value => $label): ?>
                        <option value="<?= $e($value) ?>"<?= $selected($filters['type'] ?? null, $value) ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="min_price">Precio mínimo</label>
                <input id="min_price" type="text" name="min_price" value="<?= $e($filters['min_price'] ?? '') ?>" inputmode="decimal">
            </div>

            <div class="form-group">
                <label for="max_price">Precio máximo</label>
                <input id="max_price" type="text" name="max_price" value="<?= $e($filters['max_price'] ?? '') ?>" inputmode="decimal">
            </div>

            <div class="form-group">
                <label for="creator">Creator</label>
                <input id="creator" type="text" name="creator" value="<?= $e($filters['creator'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="sort">Orden</label>
                <select id="sort" name="sort">
                    <option value="newest"<?= $selected($filters['sort'] ?? 'newest', 'newest') ?>>Más recientes</option>
                    <option value="oldest"<?= $selected($filters['sort'] ?? 'newest', 'oldest') ?>>Más antiguos</option>
                    <option value="price_asc"<?= $selected($filters['sort'] ?? 'newest', 'price_asc') ?>>Precio: menor a mayor</option>
                    <option value="price_desc"<?= $selected($filters['sort'] ?? 'newest', 'price_desc') ?>>Precio: mayor a menor</option>
                </select>
            </div>

            <div class="filter-actions">
                <button class="btn btn-primary" type="submit">Filtrar</button>
                <a class="btn btn-ghost" href="<?= $e($indexUrl) ?>">Limpiar</a>
            </div>
        </div>
    </form>

    <?php if ($chips !== []): ?>
        <ul class="filter-chips" aria-label="Filtros activos">
            <?php foreach ($chips as $chip): ?>
                <li class="filter-chip"><?= $e($chip) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($listings === []): ?>
        <div class="empty-state">
            <p>No se encontraron publicaciones con estos filtros.</p>
            <?php if ($hasActiveFilters): ?>
                <p class="empty-state-actions">
                    <a class="btn btn-secondary" href="<?= $e($indexUrl) ?>">Limpiar filtros</a>
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="listing-grid">
            <?php foreach ($listings as $listing): ?>
                <?php
                $listingUrl = $indexUrl . '/' . $listing['slug'];
                $headingTag = 'h2';
                $showCreator = true;
                $isOwner = $currentUserId !== null && (int) ($listing['owner_user_id'] ?? 0) === $currentUserId;
                $showFavorite = $currentUserId !== null && !$isOwner && is_string($csrf ?? null);
                $isFavorite = $showFavorite && isset($favoritedListingIds[(int) $listing['id']]);
                $favoriteSource = 'marketplace';
                $favoriteActionUrl = $showFavorite
                    ? $favoriteStoreBaseUrl . '/' . $listing['id'] . ($isFavorite ? '/delete' : '')
                    : null;
                require dirname(__DIR__) . '/partials/listing-card.php';
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <nav class="pagination" aria-label="Paginación">
        <?php if ($currentPage > 1): ?>
            <a class="btn btn-ghost" href="<?= $e($pageUrl($query, $currentPage - 1)) ?>">Anterior</a>
        <?php else: ?>
            <span class="pagination-disabled">Anterior</span>
        <?php endif; ?>

        <span>Página <?= $e($currentPage) ?> de <?= $e($lastPage) ?></span>

        <?php if ($currentPage < $lastPage): ?>
            <a class="btn btn-ghost" href="<?= $e($pageUrl($query, $currentPage + 1)) ?>">Siguiente</a>
        <?php else: ?>
            <span class="pagination-disabled">Siguiente</span>
        <?php endif; ?>
    </nav>
</div>
<?php
\App\Core\Layout::render('Marketplace - ERONYX', (string) ob_get_clean());
