<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container admin-shell">
    <?php require dirname(__DIR__) . '/partials/nav.php'; ?>

    <header class="page-header">
        <h1 class="page-title">Usuario #<?= $e((string) $user['id']) ?></h1>
        <p class="page-subtitle"><a href="<?= $e($indexUrl) ?>">Volver al listado</a></p>
    </header>

    <?php if (($notice ?? '') !== ''): ?>
        <div class="alert alert-success" role="status"><?= $e($notice) ?></div>
    <?php endif; ?>

    <div class="admin-detail-grid">
        <section class="admin-panel">
            <h2>Cuenta</h2>
            <dl class="admin-dl">
                <dt>Email</dt><dd><?= $e($user['email']) ?></dd>
                <dt>Usuario</dt><dd><?= $e($user['username'] ?? '') ?></dd>
                <dt>Nombre</dt><dd><?= $e($user['display_name'] ?? '') ?></dd>
                <dt>Bio</dt><dd><?= $e($user['bio'] ?? '') ?></dd>
                <dt>Estado</dt><dd><span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $user['status'])) ?>"><?= $e(\App\Core\Layout::statusLabel((string) $user['status'])) ?></span></dd>
                <dt>Email verificado</dt><dd><?= !empty($user['email_verified']) ? $e((string) $user['email_verified_at']) : 'Pendiente' ?></dd>
                <dt>MFA</dt><dd><?= !empty($user['mfa_enabled']) ? 'Activado' : 'No activado' ?></dd>
                <dt>Roles</dt><dd><?= $e(implode(', ', $user['roles'] ?? [])) ?></dd>
                <dt>Alta</dt><dd><?= $e($user['created_at']) ?></dd>
                <dt>Actualizado</dt><dd><?= $e($user['updated_at']) ?></dd>
                <dt>Último login</dt><dd><?= $e($user['last_login_at'] ?? '—') ?></dd>
            </dl>
        </section>
        <section class="admin-panel">
            <h2>Consentimientos</h2>
            <?php if (($user['consents'] ?? []) === []): ?>
                <p class="muted">Sin consentimientos registrados.</p>
            <?php else: ?>
                <ul class="legal-consent-list">
                    <?php foreach ($user['consents'] as $consent): ?>
                        <li>
                            <?= $e($consent['consent_type']) ?>
                            · <?= $e($consent['document_version']) ?>
                            · <?= $e($consent['accepted_at']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="admin-panel">
            <h2>Actividad</h2>
            <dl class="admin-dl">
                <dt>Listings</dt><dd><?= $e((string) ($user['counts']['listings'] ?? 0)) ?></dd>
                <dt>Pedidos</dt><dd><?= $e((string) ($user['counts']['orders'] ?? 0)) ?></dd>
                <dt>Favoritos</dt><dd><?= $e((string) ($user['counts']['favorites'] ?? 0)) ?></dd>
                <dt>Conversaciones</dt><dd><?= $e((string) ($user['counts']['conversations'] ?? 0)) ?></dd>
                <dt>Reportes enviados</dt><dd><?= $e((string) ($user['counts']['reports'] ?? 0)) ?></dd>
                <dt>Notificaciones</dt><dd><?= $e((string) ($user['counts']['notifications'] ?? 0)) ?></dd>
            </dl>
        </section>
        <?php if (!empty($user['creator_profile'])): ?>
            <section class="admin-panel">
                <h2>Creator</h2>
                <p>Estado: <?= $e(\App\Core\Layout::statusLabel((string) $user['creator_profile']['status'])) ?></p>
                <p><a href="<?= $e(\App\Core\Layout::url('/admin/creators/' . $user['id'])) ?>">Ver ficha creator</a></p>
            </section>
        <?php endif; ?>
    </div>

    <?php if (!empty($canManage) && ($user['status'] ?? '') === 'active'): ?>
        <section class="danger-zone">
            <h2>Zona sensible</h2>
            <p class="muted">Suspender impide el acceso de esta cuenta. No es irreversible.</p>
            <form method="post" action="<?= $e($suspendUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-danger" type="submit">Suspender cuenta</button>
            </form>
        </section>
    <?php elseif (!empty($canManage) && ($user['status'] ?? '') === 'suspended'): ?>
        <section class="danger-zone">
            <h2>Zona sensible</h2>
            <p class="muted">La cuenta está suspendida. Reactivarla restaura el acceso.</p>
            <form method="post" action="<?= $e($reactivateUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-secondary" type="submit">Reactivar cuenta</button>
            </form>
        </section>
    <?php elseif (in_array('admin', $user['roles'] ?? [], true) || in_array('moderator', $user['roles'] ?? [], true)): ?>
        <p class="muted">Las cuentas admin y moderator son de solo lectura en ADMIN-2.</p>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Admin usuario - ERONYX', (string) ob_get_clean());
