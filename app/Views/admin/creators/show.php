<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container admin-shell">
    <?php require dirname(__DIR__) . '/partials/nav.php'; ?>
    <header class="page-header">
        <h1 class="page-title">Creator #<?= $e((string) $creator['user_id']) ?></h1>
        <p class="page-subtitle"><a href="<?= $e($indexUrl) ?>">Volver al listado</a> · <a href="<?= $e($userUrl) ?>">Ficha de usuario</a></p>
    </header>
    <div class="admin-detail-grid">
        <section class="admin-panel">
            <h2>Perfil</h2>
            <dl class="admin-dl">
                <dt>Email</dt><dd><?= $e($creator['email']) ?></dd>
                <dt>Usuario</dt><dd><?= $e($creator['username'] ?? '') ?></dd>
                <dt>Nombre</dt><dd><?= $e($creator['display_name'] ?? '') ?></dd>
                <dt>Bio</dt><dd><?= $e($creator['bio'] ?? '') ?></dd>
                <dt>Estado creator</dt><dd><?= $e(\App\Core\Layout::statusLabel((string) $creator['status'])) ?></dd>
                <dt>Roles</dt><dd><?= $e(implode(', ', $creator['roles'] ?? [])) ?></dd>
                <dt>Email verificado</dt><dd><?= !empty($creator['email_verified']) ? 'Sí' : 'No' ?></dd>
            </dl>
        </section>
        <section class="admin-panel">
            <h2>Listings</h2>
            <dl class="admin-dl">
                <?php foreach (($creator['listing_stats'] ?? []) as $status => $count): ?>
                    <dt><?= $e((string) $status) ?></dt><dd><?= $e((string) $count) ?></dd>
                <?php endforeach; ?>
            </dl>
        </section>
        <section class="admin-panel">
            <h2>Verificación de edad</h2>
            <?php if (empty($creator['age_verification'])): ?>
                <p class="muted">Sin registro. No hay documentos KYC.</p>
            <?php else: ?>
                <dl class="admin-dl">
                    <dt>Estado</dt><dd><?= $e(\App\Core\Layout::statusLabel((string) $creator['age_verification']['status'])) ?></dd>
                    <dt>Método</dt><dd><?= $e(\App\Core\Layout::verificationMethodLabel((string) ($creator['age_verification']['method'] ?? ''))) ?></dd>
                    <dt>Proveedor</dt><dd><?= $e($creator['age_verification']['provider'] ?? '—') ?></dd>
                    <dt>Revisado</dt><dd><?= $e($creator['age_verification']['reviewed_at'] ?? '—') ?></dd>
                    <dt>Verificado</dt><dd><?= $e($creator['age_verification']['verified_at'] ?? '—') ?></dd>
                    <dt>Caduca</dt><dd><?= $e($creator['age_verification']['expires_at'] ?? '—') ?></dd>
                </dl>
            <?php endif; ?>
        </section>
    </div>
    <section class="admin-section">
        <h2 class="admin-section-title">Historial de moderación</h2>
        <?php if (empty($creator['moderation_actions'])): ?>
            <p class="muted">Sin acciones.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($creator['moderation_actions'] as $action): ?>
                    <li><?= $e(\App\Core\Layout::moderationActionLabel((string) $action['action_type'])) ?> · <?= $e($action['created_at']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!empty($showModeratorLinks)): ?>
            <p><a href="<?= $e($moderatorUrl) ?>">Cola moderator de solicitudes</a></p>
        <?php endif; ?>
    </section>
</div>
<?php
\App\Core\Layout::render('Admin creator - ERONYX', (string) ob_get_clean());
