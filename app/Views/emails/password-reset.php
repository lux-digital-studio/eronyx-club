<?php

declare(strict_types=1);

/** @var callable(mixed):string $e */
/** @var string $displayName */
/** @var string $resetUrl */
/** @var int $expiresMinutes */
$subject = 'Restablece tu contraseña de ERONYX';
$preheader = 'Este enlace caduca en ' . $expiresMinutes . ' minutos.';
$name = \App\Services\EmailRenderer::plain($displayName);
$text = "Hola {$name},\n\n"
    . "Alguien ha solicitado restablecer la contraseña de tu cuenta ERONYX.\n\n"
    . "Usa este enlace para continuar:\n{$resetUrl}\n\n"
    . "El enlace caduca en {$expiresMinutes} minutos.\n"
    . "Si no lo has solicitado, ignora este mensaje.\n"
    . "No compartas este enlace.\n";
ob_start();
?>
<p style="margin:0 0 16px 0;">Hola <?= $e($displayName) ?>,</p>
<p style="margin:0 0 16px 0;">Alguien ha solicitado restablecer la contraseña de tu cuenta ERONYX.</p>
<p style="margin:0 0 24px 0;">
    <a href="<?= $e($resetUrl) ?>" style="display:inline-block;padding:12px 20px;background-color:#c8a96a;color:#14141c;text-decoration:none;">Restablecer contraseña</a>
</p>
<p style="margin:0 0 12px 0;">El enlace caduca en <?= $e((string) $expiresMinutes) ?> minutos. Si no lo has solicitado, ignora este mensaje.</p>
<p style="margin:0;">No compartas este enlace.</p>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/layout.php';
