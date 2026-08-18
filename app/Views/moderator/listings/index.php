<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <h1>Publicaciones pendientes</h1>

    <?php if ($listings === []): ?>
        <div class="empty-state">
            <p>No hay publicaciones pendientes.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Tipo</th>
                        <th>Precio</th>
                        <th>Actualizada</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listings as $listing): ?>
                        <tr>
                            <td><?= $e($listing['title']) ?></td>
                            <td><?= $e($listing['listing_type']) ?></td>
                            <td><?= $e($listing['price']) ?> <?= $e($listing['currency']) ?></td>
                            <td><?= $e($listing['updated_at']) ?></td>
                            <td><a href="<?= $e($baseUrl . '/' . $listing['id']) ?>">Revisar</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Moderación de publicaciones - ERONYX', (string) ob_get_clean());
