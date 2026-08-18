<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Mi cuenta</h1>
        <p class="page-subtitle">Gestiona tu perfil, pedidos y acceso creator.</p>
    </header>

    <div class="action-grid">
        <a class="action-card" href="<?= $e($profileUrl) ?>">
            <h2 class="action-card-title">Editar perfil</h2>
            <p class="action-card-copy">Nombre público, usuario, bio y avatar.</p>
        </a>
        <a class="action-card" href="<?= $e($ordersUrl) ?>">
            <h2 class="action-card-title">Mis pedidos</h2>
            <p class="action-card-copy">Consulta el estado de tus compras.</p>
        </a>
        <a class="action-card" href="<?= $e($favoritesUrl) ?>">
            <h2 class="action-card-title">Mis favoritos</h2>
            <p class="action-card-copy">Publicaciones que has guardado.</p>
        </a>
        <a class="action-card" href="<?= $e($messagesUrl) ?>">
            <h2 class="action-card-title">
                Mensajes<?php if (($unreadCount ?? 0) > 0): ?> (<?= $e((string) $unreadCount) ?>)<?php endif; ?>
            </h2>
            <p class="action-card-copy">Conversaciones con creators y buyers.</p>
        </a>
        <a class="action-card" href="<?= $e($creatorStatusUrl) ?>">
            <h2 class="action-card-title">Estado creator</h2>
            <p class="action-card-copy">Solicitud y acceso a la zona creator.</p>
        </a>
    </div>

    <form method="post" action="<?= $e($logoutUrl) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn btn-ghost" type="submit">Cerrar sesión</button>
    </form>
</div>
<?php
\App\Core\Layout::render('Mi cuenta - ERONYX', (string) ob_get_clean());
