<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - ERONYX</title>
</head>
<body>
    <main>
        <h1>Checkout</h1>
        <h2><?= $e($listing['title']) ?></h2>
        <p><?= $e($listing['price']) ?> <?= $e($listing['currency']) ?></p>
        <p><?= $e($listing['listing_type']) ?></p>

        <form method="post" action="<?= $e($action) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button type="submit">Crear pedido</button>
        </form>

        <p><a href="<?= $e($marketplaceUrl) ?>">Volver al marketplace</a></p>
    </main>
</body>
</html>
