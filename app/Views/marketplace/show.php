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
        <?php foreach ($mediaGroups['cover'] as $item): ?>
            <img src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>" alt="" width="240">
        <?php endforeach; ?>

        <p><?= $e($listing['price']) ?> <?= $e($listing['currency']) ?></p>
        <p><?= $e($listing['listing_type']) ?></p>

        <?php if (($listing['description'] ?? '') !== ''): ?>
            <p><?= $e($listing['description']) ?></p>
        <?php endif; ?>

        <?php if ($categories !== []): ?>
            <p><?= $e(implode(', ', array_map(static fn (array $category): string => $category['name'], $categories))) ?></p>
        <?php endif; ?>

        <?php if ($mediaGroups['gallery'] !== []): ?>
            <h2>Galería</h2>
            <?php foreach ($mediaGroups['gallery'] as $item): ?>
                <img src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>" alt="" width="180">
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($mediaGroups['preview'] !== []): ?>
            <h2>Preview</h2>
            <?php foreach ($mediaGroups['preview'] as $item): ?>
                <img src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>" alt="" width="180">
            <?php endforeach; ?>
        <?php endif; ?>

        <p><a href="<?= $e($indexUrl) ?>">Volver al marketplace</a></p>
    </main>
</body>
</html>
