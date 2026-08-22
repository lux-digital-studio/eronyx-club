<?php

declare(strict_types=1);

/** @var callable(mixed):string $e */
/** @var string $displayName */
$subject = 'Autenticación en dos pasos activada en ERONYX';
$preheader = 'MFA se ha activado en tu cuenta.';
$name = \App\Services\EmailRenderer::plain($displayName);
$text = "Hola {$name},\n\nLa autenticación en dos pasos (MFA) se ha activado en tu cuenta ERONYX.\nSi no has sido tú, restablece tu contraseña y contacta con soporte.\n";
ob_start();
?>
<p style="margin:0 0 16px 0;">Hola <?= $e($displayName) ?>,</p>
<p style="margin:0 0 16px 0;">La autenticación en dos pasos (MFA) se ha activado en tu cuenta ERONYX.</p>
<p style="margin:0;">Si no has sido tú, restablece tu contraseña y contacta con soporte.</p>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/layout.php';
