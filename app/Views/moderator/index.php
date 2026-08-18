<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Moderación</h1>
        <p class="page-subtitle">Revisa publicaciones y solicitudes creator.</p>
    </header>
    <div class="action-grid">
        <a class="action-card" href="<?= $e(\App\Core\Layout::url('/moderator/listings')) ?>">
            <h2 class="action-card-title">Listings pendientes</h2>
            <p class="action-card-copy">Cola de publicaciones en revisión.</p>
        </a>
        <a class="action-card" href="<?= $e(\App\Core\Layout::url('/moderator/creator-applications')) ?>">
            <h2 class="action-card-title">Solicitudes creator</h2>
            <p class="action-card-copy">Aprobación o rechazo de acceso creator.</p>
        </a>
    </div>
</div>
<?php
\App\Core\Layout::render('Moderación - ERONYX', (string) ob_get_clean());
