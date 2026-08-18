<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="dashboard-header">
        <div>
            <h1 class="page-title">Mis publicaciones</h1>
            <p class="page-subtitle">Borradores, revisiones y publicaciones activas.</p>
        </div>
        <p><a class="btn btn-primary" href="<?= $e($createUrl) ?>">Crear publicación</a></p>
    </header>

    <?php if ($listings === []): ?>
        <div class="empty-state">
            <p>No tienes publicaciones todavía.</p>
            <p class="empty-state-actions">
                <a class="btn btn-secondary" href="<?= $e($createUrl) ?>">Crear publicación</a>
            </p>
        </div>
    <?php else: ?>
        <div class="table-wrapper desktop-only">
            <table class="table">
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Estado</th>
                        <th>Tipo</th>
                        <th>Precio</th>
                        <th>Creada</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listings as $listing): ?>
                        <tr>
                            <td><?= $e($listing['title']) ?></td>
                            <td>
                                <span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $listing['status'])) ?>">
                                    <?= $e(\App\Core\Layout::statusLabel((string) $listing['status'])) ?>
                                </span>
                            </td>
                            <td><?= $e(\App\Core\Layout::listingTypeLabel((string) $listing['listing_type'])) ?></td>
                            <td><?= $e(\App\Core\Layout::formatPrice($listing['price'], $listing['currency'])) ?></td>
                            <td><?= $e($listing['created_at']) ?></td>
                            <td>
                                <a href="<?= $e($baseUrl . '/' . $listing['id']) ?>">Ver</a>
                                <?php if (in_array($listing['status'], ['draft', 'rejected'], true)): ?>
                                    · <a href="<?= $e($baseUrl . '/' . $listing['id'] . '/edit') ?>">Editar</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="orders-grid mobile-only">
            <?php foreach ($listings as $listing): ?>
                <article class="compact-card">
                    <h2><?= $e($listing['title']) ?></h2>
                    <p>
                        <span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $listing['status'])) ?>">
                            <?= $e(\App\Core\Layout::statusLabel((string) $listing['status'])) ?>
                        </span>
                    </p>
                    <p class="muted">
                        <?= $e(\App\Core\Layout::listingTypeLabel((string) $listing['listing_type'])) ?>
                        · <?= $e(\App\Core\Layout::formatPrice($listing['price'], $listing['currency'])) ?>
                    </p>
                    <p class="stack">
                        <a class="btn btn-ghost" href="<?= $e($baseUrl . '/' . $listing['id']) ?>">Ver</a>
                        <?php if (in_array($listing['status'], ['draft', 'rejected'], true)): ?>
                            <a class="btn btn-secondary" href="<?= $e($baseUrl . '/' . $listing['id'] . '/edit') ?>">Editar</a>
                        <?php endif; ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Mis publicaciones - ERONYX', (string) ob_get_clean());
