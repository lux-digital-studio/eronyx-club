<?php

declare(strict_types=1);

/** @var callable(mixed):string $e */
/** @var string $displayName */
/** @var string $listingTitle */
/** @var string $actionUrl */
$subject = 'Tu publicación ha sido suspendida';
$preheader = 'Una de tus publicaciones ha sido suspendida.';
$name = \App\Services\EmailRenderer::plain($displayName);
$title = \App\Services\EmailRenderer::plain($listingTitle);
$text = "Hola {$name},\n\nTu publicación ha sido suspendida: {$title}\nVer publicación:\n{$actionUrl}\n";
ob_start();
?>
<p style="margin:0 0 16px 0;">Hola <?= $e($displayName) ?>,</p>
<p style="margin:0 0 16px 0;">Tu publicación ha sido suspendida: <strong><?= $e($listingTitle) ?></strong></p>
<p style="margin:0;"><a href="<?= $e($actionUrl) ?>" style="color:#c8a96a;">Ver publicación</a></p>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/layout.php';
