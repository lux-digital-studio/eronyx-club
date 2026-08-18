<?php

declare(strict_types=1);

/** @var string $pageTitle */
/** @var string $content */
/** @var string $bodyClass */
/** @var array{authenticated: bool, csrf: string|null, path: string, showCreator: bool, showModerator: bool, showAdmin: bool, unreadCount: int} $nav */

$e = static fn (mixed $value): string => \App\Core\Layout::escape($value);
$bodyClass = trim('site-body ' . ($bodyClass ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= $e(\App\Core\Layout::url('/css/app.css')) ?>">
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
