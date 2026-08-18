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
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Marketplace - ERONYX</title>
</head>
<body>
    <main>
        <h1>Marketplace</h1>

        <form method="get" action="<?= $e($indexUrl) ?>">
            <label>
                Buscar
                <input type="search" name="q" value="<?= $e($filters['q'] ?? '') ?>" maxlength="100">
            </label>

            <label>
                Categoría
                <select name="category">
                    <option value="">Todas</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $e($category['slug']) ?>"<?= $selected($filters['category'] ?? null, $category['slug']) ?>>
                            <?= $e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Tipo
                <select name="type">
                    <option value="">Todos</option>
                    <?php foreach ($typeLabels as $value => $label): ?>
                        <option value="<?= $e($value) ?>"<?= $selected($filters['type'] ?? null, $value) ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Precio mínimo
                <input type="text" name="min_price" value="<?= $e($filters['min_price'] ?? '') ?>" inputmode="decimal">
            </label>

            <label>
                Precio máximo
                <input type="text" name="max_price" value="<?= $e($filters['max_price'] ?? '') ?>" inputmode="decimal">
            </label>

            <label>
                Creator
                <input type="text" name="creator" value="<?= $e($filters['creator'] ?? '') ?>">
            </label>

            <label>
                Orden
                <select name="sort">
                    <option value="newest"<?= $selected($filters['sort'] ?? 'newest', 'newest') ?>>Más recientes</option>
                    <option value="oldest"<?= $selected($filters['sort'] ?? 'newest', 'oldest') ?>>Más antiguos</option>
                    <option value="price_asc"<?= $selected($filters['sort'] ?? 'newest', 'price_asc') ?>>Precio: menor a mayor</option>
                    <option value="price_desc"<?= $selected($filters['sort'] ?? 'newest', 'price_desc') ?>>Precio: mayor a menor</option>
                </select>
            </label>

            <button type="submit">Filtrar</button>
        </form>

        <?php if ($listings === []): ?>
            <p>No se encontraron publicaciones con estos filtros.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($listings as $listing): ?>
                    <li data-listing-id="<?= $e($listing['id']) ?>" data-listing-slug="<?= $e($listing['slug']) ?>">
                        <?php if ($listing['cover_media_id'] !== null): ?>
                            <img src="<?= $e($mediaBaseUrl . '/' . $listing['cover_media_id']) ?>" alt="" width="180">
                        <?php endif; ?>
                        <h2><a href="<?= $e($indexUrl . '/' . $listing['slug']) ?>"><?= $e($listing['title']) ?></a></h2>
                        <p>
                            <?= $e($listing['price']) ?> <?= $e($listing['currency']) ?>
                            · <?= $e($typeLabels[$listing['listing_type']] ?? $listing['listing_type']) ?>
                        </p>
                        <?php if ($listing['creator_username'] !== null): ?>
                            <?php if ($listing['creator_avatar_media_id'] !== null): ?>
                                <img src="<?= $e($mediaBaseUrl . '/' . $listing['creator_avatar_media_id']) ?>" alt="" width="40">
                            <?php endif; ?>
                            <p>
                                <a href="<?= $e($creatorBaseUrl . '/' . $listing['creator_username']) ?>">
                                    <?= $e($listing['creator_display_name']) ?>
                                    @<?= $e($listing['creator_username']) ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <nav aria-label="Paginación">
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
    </main>
</body>
</html>
