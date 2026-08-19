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
        <h1>Recuperar contraseña</h1>
        <p class="auth-lead">Introduce el email de tu cuenta. Si existe, se generará una solicitud de recuperación.</p>

        <?php if ($message !== ''): ?>
            <div class="alert alert-success" role="status"><?= $e($message) ?></div>
        <?php endif; ?>

        <?php if (is_string($resetUrl) && $resetUrl !== ''): ?>
            <p class="dev-reset-url muted">Enlace de desarrollo: <a href="<?= $e($resetUrl) ?>">abrir restablecimiento</a></p>
        <?php endif; ?>

        <form method="post" action="<?= $e($action) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?= $e($oldEmail) ?>" required maxlength="255" autocomplete="email">
            </div>

            <button class="btn btn-primary" type="submit">Enviar solicitud</button>
        </form>

        <p class="auth-footer"><a href="<?= $e($loginUrl) ?>">Volver a iniciar sesión</a></p>
    </div>
</div>
<?php
\App\Core\Layout::render('Recuperar contraseña - ERONYX', (string) ob_get_clean(), 'page-auth');
