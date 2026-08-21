<?php

declare(strict_types=1);

/** @var callable(mixed):string $e */
/** @var string $appName */
/** @var string $content */
/** @var string $preheader */
$preheader = $preheader ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($subject ?? $appName) ?></title>
</head>
<body style="margin:0;padding:0;background-color:#0b0b0f;color:#f4f1ea;font-family:Georgia,'Times New Roman',serif;">
    <?php if ($preheader !== ''): ?>
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;"><?= $e($preheader) ?></div>
    <?php endif; ?>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#0b0b0f;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background-color:#14141c;border:1px solid #2a2a36;">
                    <tr>
                        <td style="padding:28px 32px 12px 32px;">
                            <p style="margin:0;letter-spacing:0.28em;font-size:12px;color:#c8a96a;"><?= $e($appName) ?></p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 32px 32px 32px;font-size:16px;line-height:1.6;color:#f4f1ea;">
                            <?= $content ?>
                            <p style="margin:28px 0 0 0;font-size:13px;color:#9a958a;">Si no esperabas este mensaje, puedes ignorarlo.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
