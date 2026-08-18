<?php

declare(strict_types=1);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Creator - ERONYX</title>
</head>
<body>
    <main>
        <h1>ERONYX - Creator</h1>
        <p>Zona privada de creador.</p>
        <?php if ($publicProfileUrl !== null): ?>
            <p><a href="<?= htmlspecialchars((string) $publicProfileUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Ver mi perfil público</a></p>
        <?php endif; ?>
    </main>
</body>
</html>
