<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$badgeClass = static fn (string $status): string => match ($status) {
    'draft' => 'badge badge-draft',
    'pending_review' => 'badge badge-pending',
    'published' => 'badge badge-published',
    'rejected' => 'badge badge-rejected',
    default => 'badge',
};
ob_start();
?>
<div class="container">
    <div class="page-header">
        <h1>Mis publicaciones</h1>
        <p><a class="btn btn-primary" href="<?= $e($createUrl) ?>">Crear publicación</a></p>
    </div>

    <?php if ($listings === []): ?>
        <div class="empty-state">
            <p>No tienes publicaciones todavía.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Estado</th>
                        <th>Tipo</th>
                        <th>Precio</th>
                        <th>Creada</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listings as $listing): ?>
                        <tr>
                            <td><?= $e($listing['title']) ?></td>
                            <td><span class="<?= $e($badgeClass($listing['status'])) ?>"><?= $e($listing['status']) ?></span></td>
                            <td><?= $e($listing['listing_type']) ?></td>
                            <td><?= $e($listing['price']) ?> <?= $e($listing['currency']) ?></td>
                            <td><?= $e($listing['created_at']) ?></td>
                            <td>
                                <a href="<?= $e($baseUrl . '/' . $listing['id']) ?>">Ver</a>
                                <?php if (in_array($listing['status'], ['draft', 'rejected'], true)): ?>
                                    <a href="<?= $e($baseUrl . '/' . $listing['id'] . '/edit') ?>">Editar</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Mis publicaciones - ERONYX', (string) ob_get_clean());
