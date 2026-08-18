<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <div class="page-header">
        <h1>ERONYX - Creator</h1>
        <p class="muted">Zona privada de creador.</p>
    </div>
    <?php if ($publicProfileUrl !== null): ?>
        <p><a href="<?= $e($publicProfileUrl) ?>">Ver mi perfil público</a></p>
    <?php endif; ?>
    <p><a class="btn btn-primary" href="<?= $e(\App\Core\Layout::url('/creator/listings')) ?>">Mis publicaciones</a></p>
</div>
<?php
\App\Core\Layout::render('Creator - ERONYX', (string) ob_get_clean());
