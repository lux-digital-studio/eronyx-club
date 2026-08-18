<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <div class="page-header">
        <h1>Mis pedidos</h1>
    </div>

    <?php if ($orders === []): ?>
        <div class="empty-state">
            <p>No hay pedidos.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($orders as $order): ?>
        <article class="card">
            <div class="card-body">
                <h2>Pedido #<?= $e($order['id']) ?></h2>
                <p><?= $e($order['status']) ?> - <?= $e($order['total_amount']) ?> <?= $e($order['currency']) ?></p>
                <p><a href="<?= $e($baseUrl . '/' . $order['id']) ?>">Ver pedido</a></p>
            </div>
        </article>
    <?php endforeach; ?>

    <p><a class="link-muted" href="<?= $e($accountUrl) ?>">Volver a mi cuenta</a></p>
</div>
<?php
\App\Core\Layout::render('Mis pedidos - ERONYX', (string) ob_get_clean());
