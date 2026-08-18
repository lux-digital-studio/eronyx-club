<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="auth-shell">
    <div class="auth-card">
        <h1>Crear cuenta</h1>
        <p class="auth-lead">Regístrate para comprar y seguir creators.</p>

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
                <label for="display_name">Nombre publico</label>
                <input id="display_name" type="text" name="display_name" value="<?= $e($old['display_name'] ?? '') ?>" required maxlength="100">
            </div>

            <div class="form-group">
                <label for="username">Usuario</label>
                <input id="username" type="text" name="username" value="<?= $e($old['username'] ?? '') ?>" required maxlength="50" autocomplete="username">
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?= $e($old['email'] ?? '') ?>" required maxlength="255" autocomplete="email">
            </div>

            <div class="form-group">
                <label for="password">Contrasena</label>
                <input id="password" type="password" name="password" required minlength="10" maxlength="255" autocomplete="new-password">
            </div>

            <div class="form-group">
                <label for="password_confirmation">Confirmar contrasena</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required minlength="10" maxlength="255" autocomplete="new-password">
            </div>

            <button class="btn btn-primary" type="submit">Registrarme</button>
        </form>

        <p><a href="<?= $e($loginUrl) ?>">Ya tengo cuenta</a></p>
    </div>
</div>
<?php
\App\Core\Layout::render('Registro - ERONYX', (string) ob_get_clean(), 'page-auth');
