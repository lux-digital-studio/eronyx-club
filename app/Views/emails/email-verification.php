<?php

declare(strict_types=1);

/** @var callable(mixed):string $e */
/** @var string $displayName */
/** @var string $verificationUrl */
/** @var int $expiresHours */
$subject = 'Verifica tu correo de ERONYX';
$preheader = 'Confirma tu correo para activar todas las funciones.';
$name = \App\Services\EmailRenderer::plain($displayName);
$text = "Hola {$name},\n\n"
    . "Bienvenido a ERONYX. Verifica tu correo para completar tu cuenta.\n\n"
    . "Usa este enlace:\n{$verificationUrl}\n\n"
    . "El enlace caduca en {$expiresHours} horas.\n"
    . "Si no has creado esta cuenta, ignora este mensaje.\n";
ob_start();
?>
<p style="margin:0 0 16px 0;">Hola <?= $e($displayName) ?>,</p>
<p style="margin:0 0 16px 0;">Bienvenido a ERONYX. Verifica tu correo para completar tu cuenta.</p>
<p style="margin:0 0 24px 0;">
    <a href="<?= $e($verificationUrl) ?>" style="display:inline-block;padding:12px 20px;background-color:#c8a96a;color:#14141c;text-decoration:none;">Verificar correo</a>
</p>
<p style="margin:0 0 12px 0;">El enlace caduca en <?= $e((string) $expiresHours) ?> horas.</p>
<p style="margin:0;">Si no has creado esta cuenta, ignora este mensaje.</p>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/layout.php';
