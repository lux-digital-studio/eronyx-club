<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="auth-shell">
    <div class="auth-card">
        <a class="auth-brand brand" href="<?= $e(\App\Core\Layout::url('/')) ?>">
            <span class="brand-mark" aria-hidden="true"></span>
            <span class="brand-text">ERONYX</span>
        </a>
        <h1>Enlace no válido</h1>
        <p class="auth-lead">Este enlace de recuperación no es válido o ha caducado.</p>
        <p class="auth-footer">
            <a href="<?= $e($forgotUrl) ?>">Solicitar uno nuevo</a>
            ·
            <a href="<?= $e($loginUrl) ?>">Iniciar sesión</a>
        </p>
    </div>
</div>
<?php
\App\Core\Layout::render('Enlace no válido - ERONYX', (string) ob_get_clean(), 'page-auth');
