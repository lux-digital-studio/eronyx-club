<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Solicitud creator - ERONYX</title>
</head>
<body>
    <main>
        <h1>Solicitud creator</h1>

        <dl>
            <dt>Nombre público</dt>
            <dd><?= $e($application['display_name']) ?></dd>
            <dt>Usuario</dt>
            <dd><?= $e($application['username']) ?></dd>
            <dt>Estado creator</dt>
            <dd><?= $e($application['status']) ?></dd>
            <dt>Declaración de edad</dt>
            <dd><?= $e($application['age_method'] ?? 'none') ?> / <?= $e($application['age_status'] ?? 'none') ?></dd>
            <dt>Fecha</dt>
            <dd><?= $e($application['created_at']) ?></dd>
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
