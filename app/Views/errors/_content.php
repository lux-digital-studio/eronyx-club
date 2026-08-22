<?php

declare(strict_types=1);

/** @var int $code */
/** @var string $title */
/** @var string $description */
/** @var bool $authenticated */
/** @var string $homeUrl */
/** @var string $marketplaceUrl */
/** @var string $accountUrl */

$e = static fn (mixed $value): string => \App\Core\Layout::escape($value);
$code = (int) ($code ?? 500);
$title = (string) ($title ?? 'Error');
$description = (string) ($description ?? '');
$authenticated = ($authenticated ?? false) === true;
?>
<div class="container error-page">
    <p class="error-code" aria-hidden="true"><?= $e((string) $code) ?></p>
    <h1 class="error-title"><?= $e($title) ?></h1>
    <p class="error-description"><?= $e($description) ?></p>
    <div class="error-actions">
        <a class="btn btn-primary" href="<?= $e($homeUrl) ?>">Volver al inicio</a>
        <a class="btn btn-secondary" href="<?= $e($marketplaceUrl) ?>">Marketplace</a>
        <?php if ($authenticated): ?>
            <a class="btn btn-ghost" href="<?= $e($accountUrl) ?>">Mi cuenta</a>
        <?php endif; ?>
    </div>
</div>
