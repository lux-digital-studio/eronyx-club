<?php

declare(strict_types=1);

ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Administración</h1>
        <p class="page-subtitle">Zona de administración.</p>
    </header>
    <section class="admin-placeholder">
        <p class="muted">Esta zona está reservada. Todavía no hay herramientas públicas de administración.</p>
    </section>
</div>
<?php
\App\Core\Layout::render('Admin - ERONYX', (string) ob_get_clean());
