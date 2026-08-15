<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Solicitudes creator - ERONYX</title>
</head>
<body>
    <main>
        <h1>Solicitudes creator</h1>

        <?php if ($applications === []): ?>
            <p>No hay solicitudes pendientes.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($applications as $application): ?>
                    <li>
                        <a href="<?= $e($baseUrl . '/' . $application['id']) ?>">
                            <?= $e($application['display_name']) ?> (@<?= $e($application['username']) ?>)
                        </a>
                        <span><?= $e($application['created_at']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>
</body>
</html>
