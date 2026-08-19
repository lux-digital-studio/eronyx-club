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
        <h1>Iniciar sesión</h1>
        <p class="auth-lead">Accede a tu cuenta ERONYX.</p>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= $e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= $e($action) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?= $e($old['email'] ?? '') ?>" required maxlength="255" autocomplete="email">
            </div>

            <div class="form-group">
                <label for="password">Contraseña</label>
                <input id="password" type="password" name="password" required maxlength="255" autocomplete="current-password">
            </div>

            <button class="btn btn-primary" type="submit">Entrar</button>
        </form>

        <p class="auth-footer">¿No tienes cuenta? <a href="<?= $e($registerUrl) ?>">Crear cuenta</a></p>
    </div>
</div>
<?php
\App\Core\Layout::render('Login - ERONYX', (string) ob_get_clean(), 'page-auth');
