<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <h1>Estado creator</h1>

    <p>Estado: <?= $e($status) ?></p>

    <?php if ($status === 'none' || $status === 'rejected'): ?>
        <p><a class="btn btn-primary" href="<?= $e($applyUrl) ?>">Solicitar ser creator</a></p>
    <?php elseif ($status === 'active'): ?>
        <p><a class="btn btn-primary" href="<?= $e($creatorUrl) ?>">Ir al panel creator</a></p>
    <?php endif; ?>

    <p><a class="link-muted" href="<?= $e($accountUrl) ?>">Volver a cuenta</a></p>
</div>
<?php
\App\Core\Layout::render('Estado creator - ERONYX', (string) ob_get_clean());
