<?php

declare(strict_types=1);

/** @var string $pageTitle */
/** @var string $content */
/** @var string $bodyClass */
/** @var array<string, mixed> $seoMeta */
/** @var array{authenticated: bool, csrf: string|null, path: string, showCreator: bool, showModerator: bool, showAdmin: bool, unreadCount: int, openReportCount: int} $nav */

$e = static fn (mixed $value): string => \App\Core\Layout::escape($value);
$bodyClass = trim('site-body ' . ($bodyClass ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($seoMeta['title'] ?? $pageTitle) ?></title>
    <meta name="description" content="<?= $e($seoMeta['description'] ?? '') ?>">
    <meta name="robots" content="<?= $e($seoMeta['robots'] ?? 'noindex, nofollow') ?>">
    <?php if (!empty($seoMeta['canonical'])): ?>
        <link rel="canonical" href="<?= $e($seoMeta['canonical']) ?>">
    <?php endif; ?>
    <meta property="og:site_name" content="ERONYX">
    <meta property="og:type" content="<?= $e($seoMeta['ogType'] ?? 'website') ?>">
    <meta property="og:title" content="<?= $e($seoMeta['ogTitle'] ?? $pageTitle) ?>">
    <meta property="og:description" content="<?= $e($seoMeta['ogDescription'] ?? '') ?>">
    <?php if (!empty($seoMeta['ogUrl'])): ?>
        <meta property="og:url" content="<?= $e($seoMeta['ogUrl']) ?>">
    <?php endif; ?>
    <?php if (!empty($seoMeta['ogImage'])): ?>
        <meta property="og:image" content="<?= $e($seoMeta['ogImage']) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?= $e($seoMeta['twitterCard'] ?? 'summary') ?>">
    <meta name="twitter:title" content="<?= $e($seoMeta['ogTitle'] ?? $pageTitle) ?>">
    <meta name="twitter:description" content="<?= $e($seoMeta['ogDescription'] ?? '') ?>">
    <?php if (!empty($seoMeta['ogImage'])): ?>
        <meta name="twitter:image" content="<?= $e($seoMeta['ogImage']) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $e(\App\Core\Layout::url('/css/app.css')) ?>">
    <?php if (!empty($seoMeta['jsonLd'])): ?>
        <script type="application/ld+json"><?= $seoMeta['jsonLd'] ?></script>
    <?php endif; ?>
</head>
<body class="<?= $e($bodyClass) ?>">
    <a class="skip-link" href="#main">Saltar al contenido</a>
    <?php require dirname(__DIR__) . '/partials/header.php'; ?>
    <main id="main" class="site-main">
        <?= $content ?>
    </main>
    <?php require dirname(__DIR__) . '/partials/footer.php'; ?>
    <script src="<?= $e(\App\Core\Layout::url('/js/app.js')) ?>" defer></script>
</body>
</html>
