<?php

declare(strict_types=1);

/** @var callable(mixed):string $e */
/** @var string $displayName */
$subject = 'Tu contraseña de ERONYX ha sido cambiada';
$preheader = 'Confirmación de cambio de contraseña.';
$name = \App\Services\EmailRenderer::plain($displayName);
$text = "Hola {$name},\n\nTu contraseña de ERONYX ha sido cambiada.\nSi no has sido tú, solicita un restablecimiento ahora.\n";
ob_start();
?>
<p style="margin:0 0 16px 0;">Hola <?= $e($displayName) ?>,</p>
<p style="margin:0 0 16px 0;">Tu contraseña de ERONYX ha sido cambiada.</p>
<p style="margin:0;">Si no has sido tú, solicita un restablecimiento de contraseña ahora.</p>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/layout.php';
