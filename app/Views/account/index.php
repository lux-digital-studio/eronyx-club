<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <div class="page-header">
        <h1>ERONYX - Mi cuenta</h1>
        <p class="muted">Sesion iniciada correctamente.</p>
    </div>

    <div class="account-links">
        <a href="<?= $e($profileUrl) ?>">Editar perfil</a>
        <a href="<?= $e($ordersUrl) ?>">Mis pedidos</a>
        <a href="<?= $e($creatorStatusUrl) ?>">Estado creator</a>
    </div>

    <form method="post" action="<?= $e($logoutUrl) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn btn-ghost" type="submit">Cerrar sesion</button>
    </form>
</div>
<?php
\App\Core\Layout::render('Mi cuenta - ERONYX', (string) ob_get_clean());
