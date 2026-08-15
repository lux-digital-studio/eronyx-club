<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Revisar publicación - ERONYX</title>
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

            <dt>Owner user ID</dt>
            <dd><?= $e($listing['owner_user_id']) ?></dd>

            <dt>Estado</dt>
            <dd><?= $e($listing['status']) ?></dd>

            <dt>Creada</dt>
            <dd><?= $e($listing['created_at']) ?></dd>

            <dt>Actualizada</dt>
            <dd><?= $e($listing['updated_at']) ?></dd>
        </dl>

        <form method="post" action="<?= $e($approveUrl) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button type="submit">Aprobar</button>
        </form>

        <form method="post" action="<?= $e($rejectUrl) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button type="submit">Rechazar</button>
        </form>

        <p><a href="<?= $e($indexUrl) ?>">Volver</a></p>
    </main>
</body>
</html>
