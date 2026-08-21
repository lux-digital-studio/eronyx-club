<?php

declare(strict_types=1);

/** @var array<string, mixed> $legal */

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container legal-doc">
    <?php require __DIR__ . '/partials/banner.php'; ?>
    <header class="page-header">
        <h1 class="page-title">Información legal</h1>
        <p class="page-subtitle">Documentos base de ERONYX. Estado: borrador pendiente de revisión profesional.</p>
    </header>
    <ul class="legal-hub">
        <li><a href="<?= $e(\App\Core\Layout::url('/terms')) ?>">Términos de uso</a></li>
        <li><a href="<?= $e(\App\Core\Layout::url('/privacy')) ?>">Política de privacidad</a></li>
        <li><a href="<?= $e(\App\Core\Layout::url('/cookies')) ?>">Política de cookies</a></li>
        <li><a href="<?= $e(\App\Core\Layout::url('/content-policy')) ?>">Política de contenido</a></li>
        <li><a href="<?= $e(\App\Core\Layout::url('/creator-rules')) ?>">Reglas para creators</a></li>
        <li><a href="<?= $e(\App\Core\Layout::url('/age-policy')) ?>">Política de mayoría de edad</a></li>
        <li><a href="<?= $e(\App\Core\Layout::url('/reporting-policy')) ?>">Reportes y retirada de contenido</a></li>
    </ul>
</div>
<?php
\App\Core\Layout::render($title ?? 'Información legal - ERONYX', (string) ob_get_clean());
