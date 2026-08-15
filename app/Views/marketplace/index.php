<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Marketplace - ERONYX</title>
</head>
<body>
    <main>
        <h1>Marketplace</h1>

        <?php if ($listings === []): ?>
            <p>No hay publicaciones disponibles.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($listings as $listing): ?>
                    <?php $categories = $categoryMap[(int) $listing['id']] ?? []; ?>
                    <li>
                        <h2><a href="<?= $e($baseUrl . '/' . $listing['slug']) ?>"><?= $e($listing['title']) ?></a></h2>
                        <p><?= $e($listing['price']) ?> <?= $e($listing['currency']) ?> · <?= $e($listing['listing_type']) ?></p>
                        <?php if ($categories !== []): ?>
                            <p><?= $e(implode(', ', array_map(static fn (array $category): string => $category['name'], $categories))) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>
</body>
</html>
