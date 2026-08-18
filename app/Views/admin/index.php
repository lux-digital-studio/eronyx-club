<?php

declare(strict_types=1);

ob_start();
?>
<div class="container">
    <h1>ERONYX - Admin</h1>
    <p class="muted">Zona privada de administracion.</p>
</div>
<?php
\App\Core\Layout::render('Admin - ERONYX', (string) ob_get_clean());
