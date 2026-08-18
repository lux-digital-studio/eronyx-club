<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Publicaciones pendientes</h1>
        <p class="page-subtitle">Cola de revisión de listings.</p>
    </header>

    <?php if ($listings === []): ?>
        <div class="empty-state">
            <p>No hay publicaciones pendientes.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper desktop-only">
            <table class="table">
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Tipo</th>
                        <th>Precio</th>
                        <th>Estado</th>
                        <th>Actualizada</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listings as $listing): ?>
                        <tr>
                            <td><?= $e($listing['title']) ?></td>
                            <td><?= $e(\App\Core\Layout::listingTypeLabel((string) $listing['listing_type'])) ?></td>
                            <td><?= $e(\App\Core\Layout::formatPrice($listing['price'], $listing['currency'])) ?></td>
                            <td>
                                <span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) ($listing['status'] ?? 'pending_review'))) ?>">
                                    <?= $e(\App\Core\Layout::statusLabel((string) ($listing['status'] ?? 'pending_review'))) ?>
                                </span>
                            </td>
                            <td><?= $e($listing['updated_at']) ?></td>
                            <td><a href="<?= $e($baseUrl . '/' . $listing['id']) ?>">Revisar</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <ul class="queue-list mobile-only">
            <?php foreach ($listings as $listing): ?>
                <li class="queue-item">
                    <div>
                        <strong><?= $e($listing['title']) ?></strong>
                        <p class="muted">
                            <?= $e(\App\Core\Layout::listingTypeLabel((string) $listing['listing_type'])) ?>
                            · <?= $e(\App\Core\Layout::formatPrice($listing['price'], $listing['currency'])) ?>
                        </p>
                    </div>
                    <a class="btn btn-secondary" href="<?= $e($baseUrl . '/' . $listing['id']) ?>">Revisar</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Moderación de publicaciones - ERONYX', (string) ob_get_clean());
