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
    'physical_product' => 'Producto físico',
    'digital_content' => 'Contenido digital',
    'service' => 'Servicio',
    'bundle' => 'Pack',
];
ob_start();
?>
<div class="container">
    <div class="page-header">
        <h1>Marketplace</h1>
    </div>

    <form class="filter-form" method="get" action="<?= $e($indexUrl) ?>">
        <div class="form-group">
            <label for="q">Buscar</label>
            <input id="q" type="search" name="q" value="<?= $e($filters['q'] ?? '') ?>" maxlength="100">
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

        <div class="form-group">
            <button class="btn btn-primary" type="submit">Filtrar</button>
        </div>
    </form>

    <?php if ($listings === []): ?>
        <div class="empty-state">
            <p>No se encontraron publicaciones con estos filtros.</p>
        </div>
    <?php else: ?>
        <ul class="listing-grid">
            <?php foreach ($listings as $listing): ?>
                <li class="listing-card" data-listing-id="<?= $e($listing['id']) ?>" data-listing-slug="<?= $e($listing['slug']) ?>">
                    <?php if ($listing['cover_media_id'] !== null): ?>
                        <img src="<?= $e($mediaBaseUrl . '/' . $listing['cover_media_id']) ?>" alt="" width="180">
                    <?php endif; ?>
                    <div class="card-body">
                        <h2><a href="<?= $e($indexUrl . '/' . $listing['slug']) ?>"><?= $e($listing['title']) ?></a></h2>
                        <p>
                            <?= $e($listing['price']) ?> <?= $e($listing['currency']) ?>
                            · <?= $e($typeLabels[$listing['listing_type']] ?? $listing['listing_type']) ?>
                        </p>
                        <?php if ($listing['creator_username'] !== null): ?>
                            <?php if ($listing['creator_avatar_media_id'] !== null): ?>
                                <img class="creator-avatar" src="<?= $e($mediaBaseUrl . '/' . $listing['creator_avatar_media_id']) ?>" alt="" width="40">
                            <?php endif; ?>
                            <p>
                                <a href="<?= $e($creatorBaseUrl . '/' . $listing['creator_username']) ?>">
                                    <?= $e($listing['creator_display_name']) ?>
                                    @<?= $e($listing['creator_username']) ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <nav class="pagination" aria-label="Paginación">
        <?php if ($currentPage > 1): ?>
            <a href="<?= $e($pageUrl($query, $currentPage - 1)) ?>">Anterior</a>
        <?php else: ?>
            <span>Anterior</span>
        <?php endif; ?>

        <span>Página <?= $e($currentPage) ?> de <?= $e($lastPage) ?></span>

        <?php if ($currentPage < $lastPage): ?>
            <a href="<?= $e($pageUrl($query, $currentPage + 1)) ?>">Siguiente</a>
        <?php else: ?>
            <span>Siguiente</span>
        <?php endif; ?>
    </nav>
</div>
<?php
\App\Core\Layout::render('Marketplace - ERONYX', (string) ob_get_clean());
