<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <h1><?= $e($listing['title']) ?></h1>

    <?php if (($error ?? null) !== null && $error !== ''): ?>
        <div class="alert alert-error" role="alert"><p><?= $e($error) ?></p></div>
    <?php endif; ?>

    <dl class="definition-list">
        <dt>Slug</dt>
        <dd><?= $e($listing['slug']) ?></dd>

        <dt>Descripción</dt>
        <dd><?= $e($listing['description'] ?? '') ?></dd>

        <dt>Tipo</dt>
        <dd><?= $e($listing['listing_type']) ?></dd>

        <dt>Estado</dt>
        <dd><?= $e($listing['status']) ?></dd>

        <dt>Precio</dt>
        <dd><?= $e($listing['price']) ?> <?= $e($listing['currency']) ?></dd>

        <dt>Visibilidad</dt>
        <dd><?= $e($listing['visibility']) ?></dd>

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

    <div class="stack">
        <?php if ($listing['status'] === 'draft'): ?>
            <a class="btn btn-secondary" href="<?= $e($editUrl) ?>">Editar</a>
            <form method="post" action="<?= $e($submitUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-primary" type="submit">Enviar a revisión</button>
            </form>
        <?php elseif ($listing['status'] === 'rejected'): ?>
            <a class="btn btn-secondary" href="<?= $e($editUrl) ?>">Editar</a>
        <?php elseif ($listing['status'] === 'pending_review'): ?>
            <span class="badge badge-pending">Pendiente de revisión</span>
        <?php elseif ($listing['status'] === 'published'): ?>
            <span class="badge badge-published">Publicado</span>
            <?php if (in_array($listing['visibility'], ['public', 'unlisted'], true)): ?>
                <a href="<?= $e($publicUrl) ?>">Ver en marketplace</a>
            <?php endif; ?>
        <?php endif; ?>
        <a href="<?= $e($mediaUrl) ?>">Gestionar imágenes</a>
        <a class="link-muted" href="<?= $e($indexUrl) ?>">Volver</a>
    </div>
</div>
<?php
\App\Core\Layout::render($listing['title'] . ' - ERONYX', (string) ob_get_clean());
