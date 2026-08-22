<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Configurar MFA</h1>
        <p class="page-subtitle">Escanea el código o introduce la clave manualmente. Confirma con un código de 6 dígitos.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="settings-section mfa-setup">
        <?php if ($qrDataUri !== ''): ?>
            <img class="mfa-qr" src="<?= $e($qrDataUri) ?>" width="192" height="192" alt="Código QR para configurar autenticación en dos pasos">
        <?php endif; ?>

        <?php if ($secret !== ''): ?>
            <p>Clave manual</p>
            <p class="mfa-secret"><code><?= $e($secret) ?></code></p>
        <?php endif; ?>

        <?php if ($otpauthUri !== ''): ?>
            <p class="form-help">Si no puedes escanear el QR, usa la clave manual en tu app Authenticator.</p>
        <?php endif; ?>

        <form method="post" action="<?= $e($confirmAction) ?>" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <div class="form-group">
                <label for="code">Código de 6 dígitos</label>
                <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code">
            </div>
            <button class="btn btn-primary" type="submit">Confirmar y activar</button>
        </form>
    </section>

    <p><a class="link-muted" href="<?= $e($cancelUrl) ?>">Cancelar</a></p>
</div>
<?php
\App\Core\Layout::render('Configurar MFA - ERONYX', (string) ob_get_clean());
