<?php

declare(strict_types=1);

/** @var array<string, mixed> $legal */

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<p class="legal-draft" role="note"><?= $e($legal['notice'] ?? '') ?></p>
<nav class="legal-nav" aria-label="Documentos legales">
    <a href="<?= $e(\App\Core\Layout::url('/legal')) ?>">Índice</a>
    <a href="<?= $e(\App\Core\Layout::url('/terms')) ?>">Términos</a>
    <a href="<?= $e(\App\Core\Layout::url('/privacy')) ?>">Privacidad</a>
    <a href="<?= $e(\App\Core\Layout::url('/cookies')) ?>">Cookies</a>
    <a href="<?= $e(\App\Core\Layout::url('/content-policy')) ?>">Contenido</a>
    <a href="<?= $e(\App\Core\Layout::url('/creator-rules')) ?>">Creators</a>
    <a href="<?= $e(\App\Core\Layout::url('/age-policy')) ?>">Edad</a>
    <a href="<?= $e(\App\Core\Layout::url('/reporting-policy')) ?>">Reportes</a>
</nav>
