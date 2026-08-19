<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="auth-shell">
    <div class="auth-card">
        <a class="auth-brand brand" href="<?= $e(\App\Core\Layout::url('/')) ?>">
            <span class="brand-mark" aria-hidden="true"></span>
            <span class="brand-text">ERONYX</span>
        </a>
        <h1>Nueva contraseña</h1>
        <p class="auth-lead">Elige una contraseña nueva para tu cuenta.</p>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= $e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= $e($action) ?>" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

            <div class="form-group">
                <label for="new_password">Nueva contraseña</label>
                <input id="new_password" type="password" name="new_password" required minlength="10" maxlength="255" autocomplete="new-password">
            </div>

            <div class="form-group">
                <label for="new_password_confirmation">Confirmar contraseña</label>
                <input id="new_password_confirmation" type="password" name="new_password_confirmation" required minlength="10" maxlength="255" autocomplete="new-password">
            </div>

            <button class="btn btn-primary" type="submit">Actualizar contraseña</button>
        </form>

        <p class="auth-footer"><a href="<?= $e($loginUrl) ?>">Volver a iniciar sesión</a></p>
    </div>
</div>
<?php
\App\Core\Layout::render('Restablecer contraseña - ERONYX', (string) ob_get_clean(), 'page-auth');
