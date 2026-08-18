<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <div class="checkout-shell">
        <header class="page-header">
            <h1 class="page-title">Checkout</h1>
            <p class="page-subtitle">Revisa el resumen y crea el pedido.</p>
        </header>

        <article class="card">
            <div class="card-body">
                <dl class="checkout-summary definition-list">
                    <dt>Publicación</dt>
                    <dd><?= $e($listing['title']) ?></dd>
                    <dt>Tipo</dt>
                    <dd><?= $e(\App\Core\Layout::listingTypeLabel((string) $listing['listing_type'])) ?></dd>
                    <dt>Precio</dt>
                    <dd class="listing-price"><?= $e(\App\Core\Layout::formatPrice($listing['price'], $listing['currency'])) ?></dd>
                </dl>

                <form method="post" action="<?= $e($action) ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-primary" type="submit">Crear pedido</button>
                </form>
            </div>
        </article>

        <p><a class="link-muted" href="<?= $e($marketplaceUrl) ?>">Volver al marketplace</a></p>
    </div>
</div>
<?php
\App\Core\Layout::render('Checkout - ERONYX', (string) ob_get_clean());
