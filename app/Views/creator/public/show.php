<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$bio = static fn (mixed $value): string => nl2br($e($value), false);
ob_start();
?>
<div class="container">
    <?php if ($profile['avatar_media_id'] !== null): ?>
        <img class="avatar-preview" src="<?= $e($mediaBaseUrl . '/' . $profile['avatar_media_id']) ?>" alt="" width="120">
    <?php endif; ?>

    <h1><?= $e($profile['display_name']) ?></h1>
    <p class="muted">@<?= $e($profile['username']) ?></p>

    <?php if (($profile['bio'] ?? '') !== ''): ?>
        <p><?= $bio($profile['bio']) ?></p>
    <?php endif; ?>

    <h2>Publicaciones</h2>
    <?php if ($listings === []): ?>
        <div class="empty-state">
            <p>No hay publicaciones públicas.</p>
        </div>
    <?php endif; ?>

    <div class="listing-grid">
        <?php foreach ($listings as $listing): ?>
            <article class="listing-card">
                <?php if (isset($coverMap[$listing['id']])): ?>
                    <img src="<?= $e($mediaBaseUrl . '/' . $coverMap[$listing['id']]) ?>" alt="" width="160">
                <?php endif; ?>
                <div class="card-body">
                    <h3><a href="<?= $e($marketplaceUrl . '/' . $listing['slug']) ?>"><?= $e($listing['title']) ?></a></h3>
                    <p><?= $e($listing['price']) ?> <?= $e($listing['currency']) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
<?php
\App\Core\Layout::render($profile['display_name'] . ' - ERONYX', (string) ob_get_clean());
