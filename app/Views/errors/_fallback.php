<?php

declare(strict_types=1);

/** @var string $pageTitle */
/** @var string $content */
/** @var string $homeUrl */
/** @var string $cssUrl */

$e = static fn (mixed $value): string => \App\Core\Layout::escape($value);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= $e($cssUrl) ?>">
</head>
<body class="site-body page-error">
    <a class="skip-link" href="#main">Saltar al contenido</a>
    <header class="site-header">
        <div class="container site-header-inner">
            <a class="brand" href="<?= $e($homeUrl) ?>">
                <span class="brand-mark" aria-hidden="true"></span>
                <span class="brand-text">ERONYX</span>
            </a>
        </div>
    </header>
    <main id="main" class="site-main">
        <?= $content ?>
    </main>
</body>
</html>
