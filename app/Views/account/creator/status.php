<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estado creator - ERONYX</title>
</head>
<body>
    <main>
        <h1>Estado creator</h1>

        <p>Estado: <?= $e($status) ?></p>

        <?php if ($status === 'none' || $status === 'rejected'): ?>
            <p><a href="<?= $e($applyUrl) ?>">Solicitar ser creator</a></p>
        <?php elseif ($status === 'active'): ?>
            <p><a href="<?= $e($creatorUrl) ?>">Ir al panel creator</a></p>
        <?php endif; ?>

        <p><a href="<?= $e($accountUrl) ?>">Volver a cuenta</a></p>
    </main>
</body>
</html>
