<?php

declare(strict_types=1);

/** @var callable(mixed):string $e */
/** @var string $displayName */
/** @var int $orderId */
/** @var string $listingTitle */
/** @var string $totalAmount */
/** @var string $currency */
/** @var string $status */
/** @var string $actionUrl */
$subject = 'Tu pedido se ha completado';
$preheader = 'Pedido #' . $orderId . ' completado.';
$name = \App\Services\EmailRenderer::plain($displayName);
$title = \App\Services\EmailRenderer::plain($listingTitle);
$text = "Hola {$name},\n\nTu pedido se ha completado.\n"
    . "Pedido: {$orderId}\nPublicación: {$title}\nTotal: {$totalAmount} {$currency}\nEstado: {$status}\n"
    . "Ver pedido:\n{$actionUrl}\n";
ob_start();
?>
<p style="margin:0 0 16px 0;">Hola <?= $e($displayName) ?>,</p>
<p style="margin:0 0 16px 0;">Tu pedido se ha completado.</p>
<p style="margin:0 0 8px 0;">Pedido: <?= $e((string) $orderId) ?></p>
<p style="margin:0 0 8px 0;">Publicación: <?= $e($listingTitle) ?></p>
<p style="margin:0 0 8px 0;">Total: <?= $e($totalAmount) ?> <?= $e($currency) ?></p>
<p style="margin:0 0 16px 0;">Estado: <?= $e($status) ?></p>
<p style="margin:0;"><a href="<?= $e($actionUrl) ?>" style="color:#c8a96a;">Ver pedido</a></p>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/layout.php';
