<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container admin-shell">
    <?php require dirname(__DIR__) . '/partials/nav.php'; ?>
    <header class="page-header">
        <h1 class="page-title"><?= $e($listing['title']) ?></h1>
        <p class="page-subtitle"><a href="<?= $e($indexUrl) ?>">Volver al listado</a> · <a href="<?= $e($ownerUrl) ?>">Owner</a></p>
    </header>
    <div class="admin-detail-grid">
        <section class="admin-panel">
            <h2>Publicación</h2>
            <dl class="admin-dl">
                <dt>Estado</dt><dd><?= $e(\App\Core\Layout::statusLabel((string) $listing['status'])) ?></dd>
                <dt>Visibilidad</dt><dd><?= $e(\App\Core\Layout::visibilityLabel((string) $listing['visibility'])) ?></dd>
                <dt>Tipo</dt><dd><?= $e(\App\Core\Layout::listingTypeLabel((string) $listing['listing_type'])) ?></dd>
                <dt>Precio</dt><dd><?= $e(\App\Core\Layout::formatPrice($listing['price'], $listing['currency'])) ?></dd>
                <dt>Slug</dt><dd><?= $e($listing['slug']) ?></dd>
                <dt>Owner</dt><dd><?= $e($listing['owner_username'] ?? '') ?></dd>
                <dt>Creado</dt><dd><?= $e($listing['created_at']) ?></dd>
                <dt>Publicado</dt><dd><?= $e($listing['published_at'] ?? '—') ?></dd>
                <dt>Actualizado</dt><dd><?= $e($listing['updated_at']) ?></dd>
            </dl>
        </section>
        <section class="admin-panel">
            <h2>Descripción</h2>
            <p><?= $e($listing['description'] ?? '') ?></p>
            <h3>Categorías</h3>
            <p><?= $e(implode(', ', array_map(static fn (array $c): string => $c['name'], $listing['categories'] ?? []))) ?></p>
            <p>Pedidos <?= $e((string) ($listing['counts']['orders'] ?? 0)) ?> · Favoritos <?= $e((string) ($listing['counts']['favorites'] ?? 0)) ?> · Reportes <?= $e((string) ($listing['counts']['reports'] ?? 0)) ?></p>
        </section>
        <section class="admin-panel">
            <h2>Media</h2>
            <?php if (empty($listing['media'])): ?>
                <p class="muted">Sin archivos.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($listing['media'] as $media): ?>
                        <li><?= $e($media['usage_type']) ?> · <?= $e($media['media_type']) ?> · <?= $e($media['visibility']) ?> · <?= $e($media['mime_type']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
    <?php if (!empty($showModeratorLinks)): ?>
        <p><a href="<?= $e($moderatorUrl) ?>">Abrir en moderación</a></p>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Admin listing - ERONYX', (string) ob_get_clean());
