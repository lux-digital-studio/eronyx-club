<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filters = $filters ?? [];
ob_start();
?>
<div class="container admin-shell">
    <?php require dirname(__DIR__) . '/partials/nav.php'; ?>
    <header class="page-header">
        <h1 class="page-title">Listings</h1>
        <p class="page-subtitle"><?= $e((string) $total) ?> resultados.</p>
    </header>
    <form class="admin-filters filter-form" method="get" action="<?= $e($indexUrl) ?>">
        <div class="form-group">
            <label for="q">Buscar</label>
            <input id="q" type="search" name="q" value="<?= $e($filters['q'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="status">Estado</label>
            <select id="status" name="status">
                <option value="">Todos</option>
                <?php foreach (['draft', 'pending_review', 'published', 'rejected', 'suspended'] as $value): ?>
                    <option value="<?= $e($value) ?>"<?= ($filters['status'] ?? '') === $value ? ' selected' : '' ?>><?= $e(\App\Core\Layout::statusLabel($value)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="visibility">Visibilidad</label>
            <select id="visibility" name="visibility">
                <option value="">Todas</option>
                <?php foreach (['public', 'private', 'unlisted'] as $value): ?>
                    <option value="<?= $e($value) ?>"<?= ($filters['visibility'] ?? '') === $value ? ' selected' : '' ?>><?= $e(\App\Core\Layout::visibilityLabel($value)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="listing_type">Tipo</label>
            <select id="listing_type" name="listing_type">
                <option value="">Todos</option>
                <?php foreach (['digital_content', 'physical_product', 'service', 'bundle'] as $value): ?>
                    <option value="<?= $e($value) ?>"<?= ($filters['listing_type'] ?? '') === $value ? ' selected' : '' ?>><?= $e(\App\Core\Layout::listingTypeLabel($value)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="creator">Creator</label>
            <input id="creator" type="text" name="creator" value="<?= $e($filters['creator'] ?? '') ?>" placeholder="id o username">
        </div>
        <div class="form-group">
            <label for="sort">Orden</label>
            <select id="sort" name="sort">
                <?php foreach (['newest' => 'Más recientes', 'oldest' => 'Más antiguos', 'updated' => 'Actualizados', 'status' => 'Estado'] as $value => $label): ?>
                    <option value="<?= $e($value) ?>"<?= ($filters['sort'] ?? 'newest') === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">Filtrar</button>
    </form>
    <?php if ($items === []): ?>
        <div class="empty-state"><p>No hay listings con esos filtros.</p></div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Título</th>
                        <th>Owner</th>
                        <th>Estado</th>
                        <th>Visibilidad</th>
                        <th>Tipo</th>
                        <th>Precio</th>
                        <th>Publicado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $listing): ?>
                        <tr>
                            <td><a href="<?= $e($indexUrl . '/' . $listing['id']) ?>"><?= $e((string) $listing['id']) ?></a></td>
                            <td><?= $e($listing['title']) ?></td>
                            <td><?= $e($listing['owner_username'] ?? '') ?></td>
                            <td><span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $listing['status'])) ?>"><?= $e(\App\Core\Layout::statusLabel((string) $listing['status'])) ?></span></td>
                            <td><?= $e(\App\Core\Layout::visibilityLabel((string) $listing['visibility'])) ?></td>
                            <td><?= $e(\App\Core\Layout::listingTypeLabel((string) $listing['listing_type'])) ?></td>
                            <td><?= $e(\App\Core\Layout::formatPrice($listing['price'], $listing['currency'])) ?></td>
                            <td><?= $e($listing['published_at'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php require dirname(__DIR__) . '/partials/pagination.php'; ?>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Admin listings - ERONYX', (string) ob_get_clean());
