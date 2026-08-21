<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Verificación de correo</h1>
        <p class="page-subtitle">Confirma tu email para usar las funciones de ERONYX.</p>
    </header>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-success" role="status"><?= $e($notice) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php if ($verified): ?>
        <p>Tu correo está verificado.</p>
        <p><a class="btn btn-primary" href="<?= $e($accountUrl) ?>">Volver a mi cuenta</a></p>
    <?php else: ?>
        <p>Debes verificar tu correo.</p>
        <form method="post" action="<?= $e($resendAction) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button class="btn btn-primary" type="submit">Reenviar email de verificación</button>
        </form>
        <p class="muted"><a href="<?= $e($accountUrl) ?>">Volver a mi cuenta</a></p>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Verificar correo - ERONYX', (string) ob_get_clean());
