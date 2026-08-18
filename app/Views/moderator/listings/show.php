<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <article class="review-panel">
        <header class="page-header">
            <div class="listing-meta">
                <span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $listing['status'])) ?>">
                    <?= $e(\App\Core\Layout::statusLabel((string) $listing['status'])) ?>
                </span>
                <span class="badge"><?= $e(\App\Core\Layout::listingTypeLabel((string) $listing['listing_type'])) ?></span>
            </div>
            <h1 class="page-title"><?= $e($listing['title']) ?></h1>
            <p class="listing-price"><?= $e(\App\Core\Layout::formatPrice($listing['price'], $listing['currency'])) ?></p>
        </header>

        <dl class="definition-list">
            <dt>Descripción</dt>
            <dd><?= $e($listing['description'] ?? '') ?></dd>

            <dt>Visibilidad</dt>
            <dd><?= $e(\App\Core\Layout::visibilityLabel((string) $listing['visibility'])) ?></dd>

            <dt>Categorías</dt>
            <dd>
                <?php if ($categories === []): ?>
                    Ninguna
                <?php else: ?>
                    <?= $e(implode(', ', array_map(static fn (array $category): string => $category['name'], $categories))) ?>
                <?php endif; ?>
            </dd>

            <dt>Creada</dt>
            <dd><?= $e($listing['created_at']) ?></dd>

            <dt>Actualizada</dt>
            <dd><?= $e($listing['updated_at']) ?></dd>
        </dl>

        <div class="review-actions">
            <form method="post" action="<?= $e($approveUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-primary" type="submit">Aprobar</button>
            </form>

            <form method="post" action="<?= $e($rejectUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-danger" type="submit">Rechazar</button>
            </form>
        </div>

        <p><a class="link-muted" href="<?= $e($indexUrl) ?>">Volver</a></p>
    </article>
</div>
<?php
\App\Core\Layout::render('Revisar publicación - ERONYX', (string) ob_get_clean());
