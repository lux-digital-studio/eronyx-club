<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$authenticated = \App\Core\Nav::context()['authenticated'];
ob_start();
?>
<div class="container hero">
    <p class="hero-kicker">Private Marketplace</p>
    <h1>ERONYX</h1>
    <p>Descubre productos y contenido de creators de ERONYX.</p>
    <div class="stack">
        <a class="btn btn-primary" href="<?= $e(\App\Core\Layout::url('/marketplace')) ?>">Explorar marketplace</a>
        <?php if ($authenticated): ?>
            <a class="btn btn-ghost" href="<?= $e(\App\Core\Layout::url('/account')) ?>">Mi cuenta</a>
        <?php else: ?>
            <a class="btn btn-secondary" href="<?= $e(\App\Core\Layout::url('/register')) ?>">Crear cuenta</a>
            <a class="btn btn-ghost" href="<?= $e(\App\Core\Layout::url('/login')) ?>">Iniciar sesión</a>
        <?php endif; ?>
    </div>
</div>
<?php
\App\Core\Layout::render('ERONYX', (string) ob_get_clean());
