<?php

declare(strict_types=1);

/** @var callable(mixed):string $e */
/** @var string $displayName */
/** @var string $actionUrl */
$subject = 'Tu solicitud de creator ha sido revisada';
$preheader = 'Tu solicitud de creator no ha sido aprobada.';
$name = \App\Services\EmailRenderer::plain($displayName);
$text = "Hola {$name},\n\nTu solicitud de creator no ha sido aprobada.\nPuedes consultar el estado en tu cuenta:\n{$actionUrl}\n";
ob_start();
?>
<p style="margin:0 0 16px 0;">Hola <?= $e($displayName) ?>,</p>
<p style="margin:0 0 16px 0;">Tu solicitud de creator no ha sido aprobada.</p>
<p style="margin:0;"><a href="<?= $e($actionUrl) ?>" style="color:#c8a96a;">Ver estado de la solicitud</a></p>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/layout.php';
