<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <article class="review-panel">
        <header class="page-header">
            <h1 class="page-title">Solicitud creator</h1>
            <p>
                <span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $application['status'])) ?>">
                    <?= $e(\App\Core\Layout::statusLabel((string) $application['status'])) ?>
                </span>
            </p>
        </header>

        <dl class="definition-list">
            <dt>Nombre público</dt>
            <dd><?= $e($application['display_name']) ?></dd>
            <dt>Usuario</dt>
            <dd>@<?= $e($application['username']) ?></dd>
            <dt>Verificación</dt>
            <dd>
                <?= $e(\App\Core\Layout::verificationMethodLabel((string) ($application['age_method'] ?? ''))) ?>
                /
                <span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) ($application['age_status'] ?? 'none'))) ?>">
                    <?= $e(\App\Core\Layout::statusLabel((string) ($application['age_status'] ?? 'none'))) ?>
                </span>
            </dd>
            <dt>Fecha</dt>
            <dd><?= $e($application['created_at']) ?></dd>
        </dl>

        <?php if (($notice ?? '') !== ''): ?>
            <div class="alert alert-success" role="status"><?= $e($notice) ?></div>
        <?php endif; ?>

        <?php if (!empty($canManualReview) && ($application['age_status'] ?? '') === 'pending'): ?>
            <div class="review-actions">
                <form method="post" action="<?= $e($verifyAgeUrl) ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-secondary" type="submit">Confirmar verificación</button>
                </form>
                <form method="post" action="<?= $e($rejectAgeUrl) ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-danger" type="submit">Rechazar verificación</button>
                </form>
            </div>
        <?php elseif (empty($canManualReview)): ?>
            <p class="muted">Estado de verificación de proveedor: solo lectura.</p>
        <?php endif; ?>

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
\App\Core\Layout::render('Solicitud creator - ERONYX', (string) ob_get_clean());
