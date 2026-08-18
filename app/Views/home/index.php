<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container hero">
    <h1>ERONYX</h1>
    <p>Marketplace privado para creators y compradores. Contenido publicado con control, visibilidad y una experiencia discreta.</p>
    <div class="stack">
        <a class="btn btn-primary" href="<?= $e(\App\Core\Layout::url('/marketplace')) ?>">Explorar marketplace</a>
        <a class="btn btn-ghost" href="<?= $e(\App\Core\Layout::url('/register')) ?>">Crear cuenta</a>
    </div>
</div>
<?php
\App\Core\Layout::render('ERONYX', (string) ob_get_clean());
