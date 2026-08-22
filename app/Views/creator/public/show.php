<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$bio = static fn (mixed $value): string => nl2br($e($value), false);
$indexUrl = $marketplaceUrl;
$creatorBaseUrl = null;
ob_start();
?>
<div class="container">
    <header class="profile-hero">
        <?php if ($profile['avatar_media_id'] !== null): ?>
            <img
                class="profile-hero-avatar"
                src="<?= $e($mediaBaseUrl . '/' . $profile['avatar_media_id']) ?>"
                alt="<?= $e('Avatar de ' . $profile['display_name']) ?>"
                width="140"
                height="140"
            >
        <?php else: ?>
            <span class="profile-hero-avatar profile-avatar-fallback" aria-hidden="true">ERONYX</span>
        <?php endif; ?>

        <div class="profile-hero-copy">
            <h1><?= $e($profile['display_name']) ?></h1>
            <p class="profile-handle muted">@<?= $e($profile['username']) ?></p>
            <?php if (($profile['bio'] ?? '') !== ''): ?>
                <p class="profile-bio"><?= $bio($profile['bio']) ?></p>
            <?php endif; ?>
            <?php if (!empty($canReport) && !empty($reportUserUrl)): ?>
                <p><a class="btn btn-ghost" href="<?= $e($reportUserUrl) ?>">Reportar usuario</a></p>
            <?php endif; ?>
        </div>
    </header>

    <section class="section">
        <header class="section-header">
            <h2>Publicaciones</h2>
        </header>

        <?php if ($listings === []): ?>
            <div class="empty-state">
                <p>No hay publicaciones públicas.</p>
            </div>
        <?php else: ?>
            <div class="listing-grid">
                <?php foreach ($listings as $listing): ?>
                    <?php
                    $listing['cover_media_id'] = $coverMap[$listing['id']] ?? null;
                    $listingUrl = $marketplaceUrl . '/' . $listing['slug'];
                    $headingTag = 'h3';
                    $showCreator = false;
                    $showFavorite = false;
                    require dirname(__DIR__, 2) . '/partials/listing-card.php';
                    ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php
$avatarUrl = $profile['avatar_media_id'] !== null
    ? \App\Core\Layout::url('/media/' . (int) $profile['avatar_media_id'])
    : null;
$seo = (new \App\Services\SeoService())->forCreator($profile, $avatarUrl);
\App\Core\Layout::render(
    ((string) ($profile['display_name'] ?? '') !== '' ? (string) $profile['display_name'] : (string) $profile['username']) . ' | ERONYX',
    (string) ob_get_clean(),
    '',
    $seo
);
