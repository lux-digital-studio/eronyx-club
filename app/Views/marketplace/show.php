<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$coverItems = $mediaGroups['cover'] ?? [];
$galleryItems = $mediaGroups['gallery'] ?? [];
$previewItems = $mediaGroups['preview'] ?? [];
ob_start();
?>
<div class="container">
    <article class="listing-detail">
        <div class="listing-detail-media">
            <?php if ($coverItems !== []): ?>
                <div class="listing-hero">
                    <?php foreach ($coverItems as $item): ?>
                        <img
                            class="media-cover"
                            src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>"
                            alt="<?= $e($listing['title']) ?>"
                            width="640"
                            height="800"
                        >
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="listing-hero">
                    <span class="listing-card-placeholder" aria-hidden="true">
                        <span class="listing-card-placeholder-brand">ERONYX</span>
                        <span class="listing-card-placeholder-label">Sin imagen</span>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($galleryItems !== []): ?>
                <section class="section">
                    <header class="section-header">
                        <h2>Galería</h2>
                    </header>
                    <ul class="media-grid">
                        <?php foreach ($galleryItems as $item): ?>
                            <li>
                                <img
                                    src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>"
                                    alt="<?= $e($listing['title']) ?>"
                                    width="240"
                                    height="300"
                                    loading="lazy"
                                >
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if ($previewItems !== []): ?>
                <section class="section">
                    <header class="section-header">
                        <h2>Preview</h2>
                    </header>
                    <ul class="media-grid">
                        <?php foreach ($previewItems as $item): ?>
                            <li>
                                <img
                                    src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>"
                                    alt="<?= $e('Preview de ' . $listing['title']) ?>"
                                    width="240"
                                    height="300"
                                    loading="lazy"
                                >
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if ($privateMediaCount > 0): ?>
                <section class="section">
                    <?php if ($canAccessPrivateMedia): ?>
                        <header class="section-header">
                            <h2>Contenido privado</h2>
                        </header>
                        <ul class="media-grid">
                            <?php foreach ($privateMedia as $item): ?>
                                <li class="<?= $item['media_type'] === 'video' ? 'media-video' : '' ?>">
                                    <?php if ($item['media_type'] === 'video'): ?>
                                        <video src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>" controls preload="metadata" controlsList="nodownload" width="360"></video>
                                    <?php else: ?>
                                        <img
                                            src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>"
                                            alt="<?= $e($listing['title']) ?>"
                                            width="240"
                                            height="300"
                                            loading="lazy"
                                        >
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="private-lock">
                            <h2>Contenido privado</h2>
                            <p>Este listing incluye contenido privado.</p>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>

        <div class="listing-detail-info">
            <div class="listing-meta">
                <span class="badge"><?= $e(\App\Core\Layout::listingTypeLabel((string) $listing['listing_type'])) ?></span>
            </div>
            <h1><?= $e($listing['title']) ?></h1>
            <p class="listing-price"><?= $e(\App\Core\Layout::formatPrice($listing['price'], $listing['currency'])) ?></p>

            <?php if ($creatorProfileUrl !== null): ?>
                <p>
                    <a href="<?= $e($creatorProfileUrl) ?>">Ver perfil de @<?= $e($creatorUsername) ?></a>
                </p>
            <?php endif; ?>

            <?php if (($listing['description'] ?? '') !== ''): ?>
                <p class="listing-description"><?= $e($listing['description']) ?></p>
            <?php endif; ?>

            <?php if ($categories !== []): ?>
                <ul class="category-list">
                    <?php foreach ($categories as $category): ?>
                        <li class="filter-chip"><?= $e($category['name']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!$isOwner): ?>
                <div class="cta-row">
                    <a class="btn btn-primary" href="<?= $e($checkoutUrl) ?>">Comprar / Desbloquear</a>
                    <?php if (!empty($canContact) && !empty($isAuthenticated) && is_string($csrf ?? null)): ?>
                        <form method="post" action="<?= $e($startConversationUrl) ?>">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="btn btn-secondary" type="submit">Contactar con creator</button>
                        </form>
                    <?php elseif (!empty($canContact)): ?>
                        <a class="btn btn-secondary" href="<?= $e($loginUrl) ?>">Contactar con creator</a>
                    <?php endif; ?>
                    <?php if (!empty($canFavorite) && is_string($csrf ?? null)): ?>
                        <form method="post" action="<?= $e($isFavorite ? $favoriteDestroyUrl : $favoriteStoreUrl) ?>">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="source" value="detail">
                            <button
                                class="btn btn-ghost listing-favorite<?= $isFavorite ? ' is-active' : '' ?>"
                                type="submit"
                                aria-label="<?= $e($isFavorite ? 'Quitar de favoritos' : 'Guardar en favoritos') ?>"
                            >
                                <span aria-hidden="true"><?= $isFavorite ? '♥' : '♡' ?></span>
                                <?= $isFavorite ? 'Guardado' : 'Guardar' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if (!empty($reportListingUrl)): ?>
                        <a class="btn btn-ghost" href="<?= $e($reportListingUrl) ?>">Reportar publicación</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p><a class="link-muted" href="<?= $e($indexUrl) ?>">Volver al marketplace</a></p>
        </div>
    </article>
</div>
<?php
$coverUrl = null;
if ($coverItems !== [] && isset($coverItems[0]['media_file_id'])) {
    $coverUrl = \App\Core\Layout::url('/media/' . (int) $coverItems[0]['media_file_id']);
}
$seo = (new \App\Services\SeoService())->forListing($listing, $coverUrl);
\App\Core\Layout::render((string) ($listing['title'] !== '' ? $listing['title'] . ' | ERONYX' : 'Publicación | ERONYX'), (string) ob_get_clean(), '', $seo);
