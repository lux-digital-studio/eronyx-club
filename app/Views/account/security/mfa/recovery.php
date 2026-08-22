<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Códigos de recuperación</h1>
        <p class="page-subtitle">Guarda estos códigos en un lugar seguro.</p>
    </header>

    <section class="settings-section recovery-codes">
        <?php if ($shownOnce && $codes !== []): ?>
            <ul class="recovery-code-list">
                <?php foreach ($codes as $code): ?>
                    <li><code class="recovery-code"><?= $e($code) ?></code></li>
                <?php endforeach; ?>
            </ul>
            <p class="muted">No volveremos a mostrar estos códigos.</p>
        <?php else: ?>
            <p>Los códigos de recuperación solo se muestran una vez. Regenera códigos desde seguridad si los has perdido.</p>
        <?php endif; ?>
    </section>

    <p><a class="link-muted" href="<?= $e($mfaUrl) ?>">Volver a MFA</a> · <a class="link-muted" href="<?= $e($accountUrl) ?>">Mi cuenta</a></p>
</div>
<?php
\App\Core\Layout::render('Códigos de recuperación - ERONYX', (string) ob_get_clean());
