<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <h1>Solicitar acceso creator</h1>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <?php foreach ($errors as $error): ?>
                <p><?= $e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= $e($action) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <div class="form-group">
            <label>
                <input type="checkbox" name="adult_confirmation" value="1">
                Declaro que soy mayor de 18 años.
            </label>
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" name="terms_confirmation" value="1">
                Acepto solicitar la revisión para acceso creator.
            </label>
        </div>

        <button class="btn btn-primary" type="submit">Enviar solicitud</button>
    </form>

    <p><a class="link-muted" href="<?= $e($statusUrl) ?>">Ver estado creator</a></p>
</div>
<?php
\App\Core\Layout::render('Solicitar acceso creator - ERONYX', (string) ob_get_clean());
