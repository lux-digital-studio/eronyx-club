<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageUrl = static function (int $page) use ($indexUrl): string {
    return $page > 1 ? $indexUrl . '?page=' . $page : $indexUrl;
};
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Mis favoritos</h1>
        <p class="page-subtitle">Publicaciones que has guardado.</p>
    </header>

    <?php if ($listings === []): ?>
        <div class="empty-state">
            <h2 class="empty-state-title">Sin favoritos</h2>
            <p class="empty-state-copy">Aún no has guardado publicaciones.</p>
            <p class="empty-state-actions">
                <a class="btn btn-secondary" href="<?= $e($marketplaceUrl) ?>">Explorar marketplace</a>
            </p>
        </div>
    <?php else: ?>
        <div class="listing-grid">
            <?php foreach ($listings as $listing): ?>
                <?php
                $listingUrl = $marketplaceUrl . '/' . $listing['slug'];
                $headingTag = 'h2';
                $showCreator = true;
                $showFavorite = true;
                $isFavorite = true;
                $favoriteSource = 'favorites';
                $favoriteActionUrl = $favoriteDestroyBaseUrl . '/' . $listing['id'] . '/delete';
                require dirname(__DIR__, 2) . '/partials/listing-card.php';
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <nav class="pagination" aria-label="Paginación">
        <?php if ($currentPage > 1): ?>
            <a class="btn btn-ghost" href="<?= $e($pageUrl($currentPage - 1)) ?>">Anterior</a>
        <?php else: ?>
            <span class="pagination-disabled">Anterior</span>
        <?php endif; ?>

        <span>Página <?= $e($currentPage) ?> de <?= $e($lastPage) ?></span>

        <?php if ($currentPage < $lastPage): ?>
            <a class="btn btn-ghost" href="<?= $e($pageUrl($currentPage + 1)) ?>">Siguiente</a>
        <?php else: ?>
            <span class="pagination-disabled">Siguiente</span>
        <?php endif; ?>
    </nav>

    <p><a class="link-muted" href="<?= $e($accountUrl) ?>">Volver a mi cuenta</a></p>
</div>
<?php
\App\Core\Layout::render('Mis favoritos - ERONYX', (string) ob_get_clean());
