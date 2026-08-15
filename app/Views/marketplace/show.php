<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($listing['title']) ?> - ERONYX</title>
</head>
<body>
    <main>
        <h1><?= $e($listing['title']) ?></h1>
        <p><?= $e($listing['price']) ?> <?= $e($listing['currency']) ?></p>
        <p><?= $e($listing['listing_type']) ?></p>

        <?php if (($listing['description'] ?? '') !== ''): ?>
            <p><?= $e($listing['description']) ?></p>
        <?php endif; ?>

        <?php if ($categories !== []): ?>
            <p><?= $e(implode(', ', array_map(static fn (array $category): string => $category['name'], $categories))) ?></p>
        <?php endif; ?>

        <p><a href="<?= $e($indexUrl) ?>">Volver al marketplace</a></p>
    </main>
</body>
</html>
