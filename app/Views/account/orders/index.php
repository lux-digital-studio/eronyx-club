<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Mis pedidos</h1>
        <p class="page-subtitle">Historial de compras en ERONYX.</p>
    </header>

    <?php if ($orders === []): ?>
        <div class="empty-state">
            <p>No hay pedidos.</p>
        </div>
    <?php else: ?>
        <div class="orders-grid">
            <?php foreach ($orders as $order): ?>
                <article class="order-card card">
                    <div class="card-body">
                        <h2>Pedido #<?= $e($order['id']) ?></h2>
                        <p>
                            <span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $order['status'])) ?>">
                                <?= $e(\App\Core\Layout::statusLabel((string) $order['status'])) ?>
                            </span>
                        </p>
                        <p class="listing-price"><?= $e(\App\Core\Layout::formatPrice($order['total_amount'], $order['currency'])) ?></p>
                        <?php if (!empty($order['created_at'])): ?>
                            <p class="muted"><?= $e($order['created_at']) ?></p>
                        <?php endif; ?>
                        <p><a class="btn btn-ghost" href="<?= $e($baseUrl . '/' . $order['id']) ?>">Ver pedido</a></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p><a class="link-muted" href="<?= $e($accountUrl) ?>">Volver a mi cuenta</a></p>
</div>
<?php
\App\Core\Layout::render('Mis pedidos - ERONYX', (string) ob_get_clean());
