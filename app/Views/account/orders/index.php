<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mis pedidos - ERONYX</title>
</head>
<body>
    <main>
        <h1>Mis pedidos</h1>

        <?php if ($orders === []): ?>
            <p>No hay pedidos.</p>
        <?php endif; ?>

        <?php foreach ($orders as $order): ?>
            <article>
                <h2>Pedido #<?= $e($order['id']) ?></h2>
                <p><?= $e($order['status']) ?> - <?= $e($order['total_amount']) ?> <?= $e($order['currency']) ?></p>
                <p><a href="<?= $e($baseUrl . '/' . $order['id']) ?>">Ver pedido</a></p>
            </article>
        <?php endforeach; ?>

        <p><a href="<?= $e($accountUrl) ?>">Volver a mi cuenta</a></p>
    </main>
</body>
</html>
