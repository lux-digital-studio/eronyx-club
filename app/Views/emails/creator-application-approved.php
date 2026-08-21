<?php

declare(strict_types=1);

/** @var callable(mixed):string $e */
/** @var string $displayName */
/** @var string $actionUrl */
$subject = 'Tu solicitud de creator ha sido aprobada';
$preheader = 'Ya puedes acceder a la zona creator.';
$name = \App\Services\EmailRenderer::plain($displayName);
$text = "Hola {$name},\n\nTu solicitud de creator ha sido aprobada.\nAccede aquí:\n{$actionUrl}\n";
ob_start();
?>
<p style="margin:0 0 16px 0;">Hola <?= $e($displayName) ?>,</p>
<p style="margin:0 0 16px 0;">Tu solicitud de creator ha sido aprobada.</p>
<p style="margin:0;"><a href="<?= $e($actionUrl) ?>" style="color:#c8a96a;">Ir a la zona creator</a></p>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/layout.php';
