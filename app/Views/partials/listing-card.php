<?php

declare(strict_types=1);

/** @var array<string, mixed> $listing */
/** @var string $mediaBaseUrl */
/** @var string $listingUrl */
/** @var string $headingTag */
/** @var bool $showCreator */
/** @var string|null $creatorBaseUrl */
/** @var bool $showFavorite */
/** @var bool $isFavorite */
/** @var string|null $favoriteActionUrl */
/** @var string|null $csrf */
/** @var string $favoriteSource */

$e = static fn (mixed $value): string => \App\Core\Layout::escape($value);
$headingTag = in_array($headingTag ?? 'h2', ['h2', 'h3'], true) ? $headingTag : 'h2';
$showCreator = $showCreator ?? false;
$showFavorite = ($showFavorite ?? false) === true;
$isFavorite = ($isFavorite ?? false) === true;
$favoriteActionUrl = is_string($favoriteActionUrl ?? null) ? $favoriteActionUrl : null;
$csrf = is_string($csrf ?? null) ? $csrf : null;
$favoriteSource = is_string($favoriteSource ?? null) && $favoriteSource !== '' ? $favoriteSource : 'marketplace';
$creatorUsername = $listing['creator_username'] ?? null;
$creatorName = $listing['creator_display_name'] ?? null;
$coverId = $listing['cover_media_id'] ?? null;
$title = (string) ($listing['title'] ?? '');
$type = (string) ($listing['listing_type'] ?? '');
$favoriteLabel = $isFavorite ? 'Quitar de favoritos' : 'Guardar en favoritos';
?>
<article class="listing-card">
    <a class="listing-card-media" href="<?= $e($listingUrl) ?>">
        <?php if ($coverId !== null): ?>
            <img
                src="<?= $e($mediaBaseUrl . '/' . $coverId) ?>"
                alt="<?= $e($title) ?>"
                width="400"
                height="500"
                loading="lazy"
            >
        <?php else: ?>
            <span class="listing-card-placeholder" aria-hidden="true">
                <span class="listing-card-placeholder-brand">ERONYX</span>
                <span class="listing-card-placeholder-label">Sin imagen</span>
            </span>
        <?php endif; ?>
        <?php if ($type !== ''): ?>
            <span class="listing-card-type"><?= $e(\App\Core\Layout::listingTypeLabel($type)) ?></span>
        <?php endif; ?>
    </a>
    <div class="listing-card-body">
        <<?= $headingTag ?> class="listing-card-title">
            <a href="<?= $e($listingUrl) ?>"><?= $e($title) ?></a>
        </<?= $headingTag ?>>

        <?php if ($showCreator && is_string($creatorUsername) && $creatorUsername !== '' && is_string($creatorBaseUrl)): ?>
            <div class="listing-card-creator">
                <?php if (($listing['creator_avatar_media_id'] ?? null) !== null): ?>
                    <img
                        class="creator-avatar"
                        src="<?= $e($mediaBaseUrl . '/' . $listing['creator_avatar_media_id']) ?>"
                        alt="<?= $e('Avatar de ' . ($creatorName ?? $creatorUsername)) ?>"
                        width="40"
                        height="40"
                        loading="lazy"
                    >
                <?php else: ?>
                    <span class="creator-avatar creator-avatar-fallback" aria-hidden="true"></span>
                <?php endif; ?>
                <a class="listing-card-creator-link" href="<?= $e($creatorBaseUrl . '/' . $creatorUsername) ?>">
                    <span class="listing-card-creator-name"><?= $e($creatorName ?? $creatorUsername) ?></span>
                    <span class="listing-card-creator-handle">@<?= $e($creatorUsername) ?></span>
                </a>
            </div>
        <?php endif; ?>

        <div class="listing-card-footer">
            <p class="listing-card-price"><?= $e(\App\Core\Layout::formatPrice($listing['price'] ?? '', $listing['currency'] ?? 'EUR')) ?></p>
            <?php if ($showFavorite && $favoriteActionUrl !== null && $csrf !== null): ?>
                <form class="listing-card-favorite" method="post" action="<?= $e($favoriteActionUrl) ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="source" value="<?= $e($favoriteSource) ?>">
                    <button
                        class="btn btn-ghost listing-favorite<?= $isFavorite ? ' is-active' : '' ?>"
                        type="submit"
                        aria-label="<?= $e($favoriteLabel) ?>"
                    >
                        <span aria-hidden="true"><?= $isFavorite ? '♥' : '♡' ?></span>
                        <?= $isFavorite ? 'Guardado' : 'Guardar' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</article>
