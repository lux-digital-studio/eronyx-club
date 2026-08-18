<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => \App\Core\Layout::escape($value);
$url = static fn (string $path): string => \App\Core\Layout::url($path);
$year = date('Y');
?>
<footer class="site-footer">
    <div class="container site-footer-inner">
        <p class="footer-brand">ERONYX</p>
        <nav class="footer-nav" aria-label="Pie de página">
            <a href="<?= $e($url('/marketplace')) ?>">Marketplace</a>
        </nav>
        <div class="footer-meta">
            <span>Términos</span>
            <span>Privacidad</span>
            <p class="footer-copy">© <?= $e($year) ?> ERONYX</p>
        </div>
    </div>
</footer>
