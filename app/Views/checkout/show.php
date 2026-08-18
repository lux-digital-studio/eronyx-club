<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <div class="auth-card">
        <h1>Checkout</h1>
        <h2><?= $e($listing['title']) ?></h2>
        <p><?= $e($listing['price']) ?> <?= $e($listing['currency']) ?></p>
        <p><?= $e($listing['listing_type']) ?></p>

        <form method="post" action="<?= $e($action) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button class="btn btn-primary" type="submit">Crear pedido</button>
        </form>

        <p><a class="link-muted" href="<?= $e($marketplaceUrl) ?>">Volver al marketplace</a></p>
    </div>
</div>
<?php
\App\Core\Layout::render('Checkout - ERONYX', (string) ob_get_clean());
