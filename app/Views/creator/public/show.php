<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$bio = static fn (mixed $value): string => nl2br($e($value), false);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($profile['display_name']) ?> - ERONYX</title>
</head>
<body>
    <main>
        <?php if ($profile['avatar_media_id'] !== null): ?>
            <img src="<?= $e($mediaBaseUrl . '/' . $profile['avatar_media_id']) ?>" alt="" width="120">
        <?php endif; ?>

        <h1><?= $e($profile['display_name']) ?></h1>
        <p>@<?= $e($profile['username']) ?></p>

        <?php if (($profile['bio'] ?? '') !== ''): ?>
            <p><?= $bio($profile['bio']) ?></p>
        <?php endif; ?>

        <h2>Publicaciones</h2>
        <?php if ($listings === []): ?>
            <p>No hay publicaciones públicas.</p>
        <?php endif; ?>

        <?php foreach ($listings as $listing): ?>
            <article>
                <?php if (isset($coverMap[$listing['id']])): ?>
                    <img src="<?= $e($mediaBaseUrl . '/' . $coverMap[$listing['id']]) ?>" alt="" width="160">
                <?php endif; ?>
                <h3><a href="<?= $e($marketplaceUrl . '/' . $listing['slug']) ?>"><?= $e($listing['title']) ?></a></h3>
                <p><?= $e($listing['price']) ?> <?= $e($listing['currency']) ?></p>
            </article>
        <?php endforeach; ?>
    </main>
</body>
</html>
