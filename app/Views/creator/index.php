<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="dashboard-header">
        <div>
            <h1 class="page-title">Creator</h1>
            <p class="page-subtitle">Zona privada de creador.</p>
        </div>
        <p><a class="btn btn-primary" href="<?= $e(\App\Core\Layout::url('/creator/listings/create')) ?>">Crear publicación</a></p>
    </header>

    <section class="dashboard-section">
        <h2 class="dashboard-section-title">Publicaciones</h2>
        <div class="quick-actions">
            <a class="action-card" href="<?= $e(\App\Core\Layout::url('/creator/listings')) ?>">
                <h3 class="action-card-title">Mis publicaciones</h3>
                <p class="action-card-copy">Gestiona borradores, revisiones y publicaciones.</p>
            </a>
            <a class="action-card" href="<?= $e(\App\Core\Layout::url('/creator/listings/create')) ?>">
                <h3 class="action-card-title">Crear publicación</h3>
                <p class="action-card-copy">Añade un nuevo listing a tu catálogo.</p>
            </a>
            <?php if ($publicProfileUrl !== null): ?>
                <a class="action-card" href="<?= $e($publicProfileUrl) ?>">
                    <h3 class="action-card-title">Perfil público</h3>
                    <p class="action-card-copy">Así te ven compradores en ERONYX.</p>
                </a>
            <?php endif; ?>
        </div>
    </section>

    <section class="dashboard-section">
        <h2 class="dashboard-section-title">Actividad</h2>
        <div class="quick-actions">
            <a class="action-card" href="<?= $e(\App\Core\Layout::url('/account/messages')) ?>">
                <h3 class="action-card-title">Mensajes</h3>
                <p class="action-card-copy">Conversaciones con buyers.</p>
            </a>
            <a class="action-card" href="<?= $e(\App\Core\Layout::url('/account/orders')) ?>">
                <h3 class="action-card-title">Pedidos</h3>
                <p class="action-card-copy">Historial de compras de tu cuenta.</p>
            </a>
            <a class="action-card" href="<?= $e(\App\Core\Layout::url('/account/creator')) ?>">
                <h3 class="action-card-title">Solicitud y verificación</h3>
                <p class="action-card-copy">Estado de tu acceso creator y verificación de edad.</p>
            </a>
        </div>
    </section>
</div>
<?php
\App\Core\Layout::render('Creator - ERONYX', (string) ob_get_clean());
