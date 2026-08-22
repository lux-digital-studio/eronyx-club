<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$enabled = ($status['status'] ?? '') === 'enabled';
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Autenticación en dos pasos</h1>
        <p class="page-subtitle">Protege tu cuenta con una app Authenticator.</p>
    </header>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-success" role="status"><?= $e($notice) ?></div>
    <?php endif; ?>

    <section class="settings-section mfa-status">
        <h2>Estado</h2>
        <p><?= $enabled ? 'Activado' : 'No activado' ?></p>
        <?php if ($enabled): ?>
            <p class="muted">Códigos de recuperación restantes: <?= $e((string) ($status['unused_codes'] ?? 0)) ?></p>
        <?php endif; ?>
    </section>

    <?php if (!$enabled): ?>
        <section class="settings-section mfa-setup">
            <h2>Activar MFA</h2>
            <p>Usa una app Authenticator. El secreto se muestra solo durante la configuración.</p>
            <form method="post" action="<?= $e($setupAction) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-primary" type="submit">Activar</button>
            </form>
        </section>
    <?php else: ?>
        <section class="settings-section">
            <h2>Regenerar códigos de recuperación</h2>
            <p>Invalida los códigos anteriores. Requiere contraseña y un código MFA.</p>
            <form method="post" action="<?= $e($regenerateAction) ?>" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <div class="form-group">
                    <label for="regen_password">Contraseña actual</label>
                    <input id="regen_password" type="password" name="current_password" required maxlength="255" autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label for="regen_mfa">Código TOTP o de recuperación</label>
                    <input id="regen_mfa" type="text" name="mfa_code" required maxlength="64" autocomplete="off">
                </div>
                <button class="btn btn-primary" type="submit">Regenerar códigos</button>
            </form>
        </section>

        <section class="settings-section">
            <h2>Desactivar MFA</h2>
            <p>Requiere contraseña y un código TOTP o de recuperación.</p>
            <form method="post" action="<?= $e($disableAction) ?>" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <div class="form-group">
                    <label for="disable_password">Contraseña actual</label>
                    <input id="disable_password" type="password" name="current_password" required maxlength="255" autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label for="disable_mfa">Código TOTP o de recuperación</label>
                    <input id="disable_mfa" type="text" name="mfa_code" required maxlength="64" autocomplete="off">
                </div>
                <button class="btn btn-ghost" type="submit">Desactivar</button>
            </form>
        </section>
    <?php endif; ?>

    <p><a class="link-muted" href="<?= $e($passwordUrl) ?>">Contraseña</a> · <a class="link-muted" href="<?= $e($accountUrl) ?>">Volver a mi cuenta</a></p>
</div>
<?php
\App\Core\Layout::render('MFA - ERONYX', (string) ob_get_clean());
