<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Solicitar acceso creator</h1>
        <p class="page-subtitle">Confirma tu mayoría de edad (declaración legal) y envía la solicitud. Esta declaración no sustituye una verificación de identidad.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <?php foreach ($errors as $error): ?>
                <p><?= $e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="form-section">
        <form method="post" action="<?= $e($action) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

            <label class="checkbox-row">
                <input type="checkbox" name="adult_confirmation" value="1">
                <span>Declaro que soy mayor de 18 años según la <a href="<?= $e(\App\Core\Layout::url('/age-policy')) ?>">política de mayoría de edad</a>. Esta casilla es una declaración legal, no una verificación KYC.</span>
            </label>

            <label class="checkbox-row">
                <input type="checkbox" name="accept_creator_rules" value="1">
                <span>Acepto las <a href="<?= $e(\App\Core\Layout::url('/creator-rules')) ?>">reglas para creators</a>.</span>
            </label>

            <label class="checkbox-row">
                <input type="checkbox" name="accept_content_policy" value="1">
                <span>Acepto la <a href="<?= $e(\App\Core\Layout::url('/content-policy')) ?>">política de contenido</a>.</span>
            </label>

            <button class="btn btn-primary" type="submit">Enviar solicitud</button>
        </form>
    </section>

    <p><a class="link-muted" href="<?= $e($statusUrl) ?>">Ver estado creator</a></p>
</div>
<?php
\App\Core\Layout::render('Solicitar acceso creator - ERONYX', (string) ob_get_clean());
