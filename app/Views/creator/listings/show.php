<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($listing['title']) ?> - ERONYX</title>
</head>
<body>
    <main>
        <h1><?= $e($listing['title']) ?></h1>

        <dl>
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

        <p>
            <?php if ($listing['status'] === 'draft'): ?>
                <a href="<?= $e($editUrl) ?>">Editar</a>
                <form method="post" action="<?= $e($submitUrl) ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button type="submit">Enviar a revisión</button>
                </form>
            <?php elseif ($listing['status'] === 'rejected'): ?>
                <a href="<?= $e($editUrl) ?>">Editar</a>
            <?php elseif ($listing['status'] === 'pending_review'): ?>
                Pendiente de revisión
            <?php elseif ($listing['status'] === 'published'): ?>
                Publicado
                <?php if ($listing['visibility'] === 'public'): ?>
                    <a href="<?= $e($publicUrl) ?>">Ver en marketplace</a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="<?= $e($mediaUrl) ?>">Gestionar imágenes</a>
            <a href="<?= $e($indexUrl) ?>">Volver</a>
        </p>
    </main>
</body>
</html>
