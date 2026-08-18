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
    </header>

    <div class="quick-actions">
        <a class="action-card" href="<?= $e(\App\Core\Layout::url('/creator/listings')) ?>">
            <h2 class="action-card-title">Mis publicaciones</h2>
            <p class="action-card-copy">Gestiona borradores, revisiones y publicaciones.</p>
        </a>
        <a class="action-card" href="<?= $e(\App\Core\Layout::url('/creator/listings/create')) ?>">
            <h2 class="action-card-title">Crear publicación</h2>
            <p class="action-card-copy">Añade un nuevo listing a tu catálogo.</p>
        </a>
        <?php if ($publicProfileUrl !== null): ?>
            <a class="action-card" href="<?= $e($publicProfileUrl) ?>">
                <h2 class="action-card-title">Ver perfil público</h2>
                <p class="action-card-copy">Así te ven compradores en ERONYX.</p>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php
\App\Core\Layout::render('Creator - ERONYX', (string) ob_get_clean());
