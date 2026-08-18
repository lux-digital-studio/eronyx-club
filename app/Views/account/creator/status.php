<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Estado creator</h1>
        <p class="page-subtitle">Consulta el resultado de tu solicitud.</p>
    </header>

    <section class="status-card">
        <p>
            <span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $status)) ?>">
                <?= $e(\App\Core\Layout::statusLabel((string) $status)) ?>
            </span>
        </p>

        <?php if ($status === 'none' || $status === 'rejected'): ?>
            <p class="muted">Puedes enviar una solicitud para acceso creator.</p>
            <p><a class="btn btn-primary" href="<?= $e($applyUrl) ?>">Solicitar ser creator</a></p>
        <?php elseif ($status === 'pending'): ?>
            <p class="muted">Tu solicitud está en revisión.</p>
        <?php elseif ($status === 'active'): ?>
            <p class="muted">Tu acceso creator está activo.</p>
            <p><a class="btn btn-primary" href="<?= $e($creatorUrl) ?>">Ir al panel creator</a></p>
        <?php elseif ($status === 'suspended'): ?>
            <p class="muted">El acceso creator está suspendido.</p>
        <?php endif; ?>
    </section>

    <p><a class="link-muted" href="<?= $e($accountUrl) ?>">Volver a cuenta</a></p>
</div>
<?php
\App\Core\Layout::render('Estado creator - ERONYX', (string) ob_get_clean());
