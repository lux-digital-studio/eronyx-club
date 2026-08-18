<?php

declare(strict_types=1);

ob_start();
?>
<div class="container">
    <h1>ERONYX - Moderacion</h1>
    <p class="muted">Zona privada de moderacion.</p>
    <div class="account-links">
        <a href="<?= htmlspecialchars(\App\Core\Layout::url('/moderator/listings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Publicaciones pendientes</a>
        <a href="<?= htmlspecialchars(\App\Core\Layout::url('/moderator/creator-applications'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Solicitudes creator</a>
    </div>
</div>
<?php
\App\Core\Layout::render('Moderacion - ERONYX', (string) ob_get_clean());
