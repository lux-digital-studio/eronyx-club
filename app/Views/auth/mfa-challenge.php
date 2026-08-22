<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="auth-shell">
    <div class="auth-card mfa-challenge">
        <a class="auth-brand brand" href="<?= $e(\App\Core\Layout::url('/')) ?>">
            <span class="brand-mark" aria-hidden="true"></span>
            <span class="brand-text">ERONYX</span>
        </a>
        <h1>Verificación MFA</h1>
        <p class="auth-lead">Introduce un código de tu app Authenticator o un código de recuperación.</p>

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

            <fieldset class="form-group">
                <legend>Método</legend>
                <label><input type="radio" name="method" value="totp" checked> App Authenticator</label>
                <label><input type="radio" name="method" value="recovery"> Código de recuperación</label>
            </fieldset>

            <div class="form-group">
                <label for="code">Código TOTP</label>
                <input id="code" type="text" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
            </div>

            <div class="form-group">
                <label for="recovery_code">Código de recuperación</label>
                <input id="recovery_code" type="text" name="recovery_code" maxlength="64" autocomplete="off">
            </div>

            <button class="btn btn-primary" type="submit">Verificar</button>
        </form>

        <p class="auth-footer"><a href="<?= $e($loginUrl) ?>">Volver al login</a></p>
    </div>
</div>
<?php
\App\Core\Layout::render('Verificación MFA - ERONYX', (string) ob_get_clean(), 'page-auth');
