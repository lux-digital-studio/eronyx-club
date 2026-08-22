<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Cambiar contraseña</h1>
        <p class="page-subtitle">Actualiza la contraseña de tu cuenta ERONYX.</p>
    </header>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-success" role="status"><?= $e($notice) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="settings-section">
        <h2>Seguridad de la cuenta</h2>
        <form method="post" action="<?= $e($action) ?>" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

            <div class="form-group">
                <label for="current_password">Contraseña actual</label>
                <input id="current_password" type="password" name="current_password" required maxlength="255" autocomplete="current-password">
            </div>

            <div class="form-group">
                <label for="new_password">Nueva contraseña</label>
                <input id="new_password" type="password" name="new_password" required minlength="10" maxlength="255" autocomplete="new-password">
                <p class="form-help">Mínimo 10 caracteres.</p>
            </div>

            <div class="form-group">
                <label for="new_password_confirmation">Confirmar nueva contraseña</label>
                <input id="new_password_confirmation" type="password" name="new_password_confirmation" required minlength="10" maxlength="255" autocomplete="new-password">
            </div>

            <button class="btn btn-primary" type="submit">Guardar contraseña</button>
        </form>
    </section>

    <p><a class="link-muted" href="<?= $e(\App\Core\Layout::url('/account/security/mfa')) ?>">Autenticación en dos pasos</a> · <a class="link-muted" href="<?= $e($accountUrl) ?>">Volver a mi cuenta</a></p>
</div>
<?php
\App\Core\Layout::render('Cambiar contraseña - ERONYX', (string) ob_get_clean());
