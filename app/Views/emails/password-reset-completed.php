<?php

declare(strict_types=1);

/** @var callable(mixed):string $e */
/** @var string $displayName */
$subject = 'Tu contraseña de ERONYX ha sido restablecida';
$preheader = 'Confirmación de restablecimiento de contraseña.';
$name = \App\Services\EmailRenderer::plain($displayName);
$text = "Hola {$name},\n\nTu contraseña de ERONYX ha sido restablecida.\nSi no has sido tú, solicita un nuevo restablecimiento ahora.\n";
ob_start();
?>
<p style="margin:0 0 16px 0;">Hola <?= $e($displayName) ?>,</p>
<p style="margin:0 0 16px 0;">Tu contraseña de ERONYX ha sido restablecida.</p>
<p style="margin:0;">Si no has sido tú, solicita un nuevo restablecimiento ahora.</p>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/layout.php';
