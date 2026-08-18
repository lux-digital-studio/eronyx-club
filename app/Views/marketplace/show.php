<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <article class="card">
        <div class="card-body">
            <h1><?= $e($listing['title']) ?></h1>
            <?php foreach ($mediaGroups['cover'] as $item): ?>
                <img class="media-cover" src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>" alt="" width="240">
            <?php endforeach; ?>

            <p><?= $e($listing['price']) ?> <?= $e($listing['currency']) ?></p>
            <p><?= $e($listing['listing_type']) ?></p>

            <?php if (($listing['description'] ?? '') !== ''): ?>
                <p><?= $e($listing['description']) ?></p>
            <?php endif; ?>

            <?php if ($creatorProfileUrl !== null): ?>
                <p><a href="<?= $e($creatorProfileUrl) ?>">Ver perfil de @<?= $e($creatorUsername) ?></a></p>
            <?php endif; ?>

            <?php if (!$isOwner): ?>
                <p><a class="btn btn-primary" href="<?= $e($checkoutUrl) ?>">Comprar / desbloquear</a></p>
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

            <?php if ($privateMediaCount > 0): ?>
                <h2>Contenido privado</h2>
                <?php if ($canAccessPrivateMedia): ?>
                    <?php foreach ($privateMedia as $item): ?>
                        <?php if ($item['media_type'] === 'video'): ?>
                            <video src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>" controls controlsList="nodownload" width="360"></video>
                        <?php else: ?>
                            <img src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>" alt="" width="180">
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Contenido privado disponible: <?= $e($privateMediaCount) ?> elemento(s).</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="card-footer">
            <a class="link-muted" href="<?= $e($indexUrl) ?>">Volver al marketplace</a>
        </div>
    </article>
</div>
<?php
\App\Core\Layout::render($listing['title'] . ' - ERONYX', (string) ob_get_clean());
