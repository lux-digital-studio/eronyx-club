<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => \App\Core\Layout::escape($value);
$url = static fn (string $path): string => \App\Core\Layout::url($path);
$year = date('Y');
?>
<footer class="site-footer">
    <div class="container site-footer-inner">
        <p class="footer-brand">ERONYX</p>
        <div class="footer-cols">
            <nav class="footer-col" aria-label="Explorar">
                <h2>Explorar</h2>
                <a href="<?= $e($url('/marketplace')) ?>">Marketplace</a>
                <a href="<?= $e($url('/legal')) ?>">Información legal</a>
            </nav>
            <nav class="footer-col" aria-label="Legal">
                <h2>Legal</h2>
                <a href="<?= $e($url('/terms')) ?>">Términos</a>
                <a href="<?= $e($url('/privacy')) ?>">Privacidad</a>
                <a href="<?= $e($url('/cookies')) ?>">Cookies</a>
            </nav>
            <nav class="footer-col" aria-label="Seguridad">
                <h2>Seguridad</h2>
                <a href="<?= $e($url('/content-policy')) ?>">Política de contenido</a>
                <a href="<?= $e($url('/creator-rules')) ?>">Reglas para creators</a>
                <a href="<?= $e($url('/age-policy')) ?>">Mayoría de edad</a>
                <a href="<?= $e($url('/reporting-policy')) ?>">Reportar contenido</a>
            </nav>
        </div>
        <div class="footer-meta">
            <p class="footer-copy">© <?= $e($year) ?> ERONYX · 18+</p>
        </div>
    </div>
</footer>
