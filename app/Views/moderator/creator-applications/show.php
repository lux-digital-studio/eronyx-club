<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <h1>Solicitud creator</h1>

    <dl class="definition-list">
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

    <div class="stack">
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
</div>
<?php
\App\Core\Layout::render('Solicitud creator - ERONYX', (string) ob_get_clean());
