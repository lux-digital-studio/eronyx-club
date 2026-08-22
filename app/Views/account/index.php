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

    <?php if (empty($emailVerified)): ?>
        <div class="alert alert-error" role="status">
            Debes verificar tu correo.
            <a href="<?= $e($verifyEmailUrl) ?>">Reenviar email de verificación</a>
        </div>
    <?php else: ?>
        <p class="muted">Email verificado.</p>
    <?php endif; ?>

    <section class="dashboard-section">
        <h2 class="dashboard-section-title">Cuenta</h2>
        <div class="action-grid">
            <a class="action-card" href="<?= $e($profileUrl) ?>">
                <h3 class="action-card-title">Perfil</h3>
                <p class="action-card-copy">Nombre público, usuario, bio y avatar.</p>
            </a>
            <a class="action-card" href="<?= $e($legalUrl) ?>">
                <h3 class="action-card-title">Legal y consentimientos</h3>
                <p class="action-card-copy">Versiones aceptadas de términos, privacidad y reglas.</p>
            </a>
        </div>
        <section class="form-section">
            <h3>Consentimientos</h3>
            <?php if (($consents ?? []) === []): ?>
                <p class="muted">No hay consentimientos registrados.</p>
            <?php else: ?>
                <ul class="legal-consent-list">
                    <?php foreach ($consents as $consent): ?>
                        <li>
                            <?= $e($consent['consent_type']) ?>
                            · versión <?= $e($consent['document_version']) ?>
                            · <?= $e($consent['accepted_at']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p><a href="<?= $e($legalUrl) ?>">Ver detalle legal</a></p>
        </section>
    </section>

    <section class="dashboard-section">
        <h2 class="dashboard-section-title">Actividad</h2>
        <div class="action-grid">
            <a class="action-card" href="<?= $e($ordersUrl) ?>">
                <h3 class="action-card-title">Pedidos</h3>
                <p class="action-card-copy">Consulta el estado de tus compras.</p>
            </a>
            <a class="action-card" href="<?= $e($favoritesUrl) ?>">
                <h3 class="action-card-title">Favoritos</h3>
                <p class="action-card-copy">Publicaciones que has guardado.</p>
            </a>
            <a class="action-card" href="<?= $e($messagesUrl) ?>">
                <h3 class="action-card-title">
                    Mensajes<?php if (($unreadCount ?? 0) > 0): ?> (<?= $e((string) $unreadCount) ?>)<?php endif; ?>
                </h3>
                <p class="action-card-copy">Conversaciones con creators y buyers.</p>
            </a>
            <a class="action-card" href="<?= $e($notificationsUrl) ?>">
                <h3 class="action-card-title">
                    Notificaciones<?php if (($notificationUnreadCount ?? 0) > 0): ?> (<?= $e((string) $notificationUnreadCount) ?>)<?php endif; ?>
                </h3>
                <p class="action-card-copy">Avisos de mensajes, pedidos y moderación.</p>
            </a>
        </div>
    </section>

    <section class="dashboard-section">
        <h2 class="dashboard-section-title">Creator</h2>
        <div class="action-grid">
            <a class="action-card" href="<?= $e($creatorStatusUrl) ?>">
                <h3 class="action-card-title">Estado creator</h3>
                <p class="action-card-copy">Solicitud, verificación de edad y acceso a la zona creator.</p>
            </a>
        </div>
    </section>

    <section class="dashboard-section">
        <h2 class="dashboard-section-title">Seguridad</h2>
        <div class="action-grid">
            <a class="action-card" href="<?= $e($securityUrl) ?>">
                <h3 class="action-card-title">Contraseña</h3>
                <p class="action-card-copy">Cambiar la contraseña de la cuenta.</p>
            </a>
        </div>
        <form method="post" action="<?= $e($logoutUrl) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button class="btn btn-ghost" type="submit">Cerrar sesión</button>
        </form>
    </section>
</div>
<?php
\App\Core\Layout::render('Mi cuenta - ERONYX', (string) ob_get_clean());
