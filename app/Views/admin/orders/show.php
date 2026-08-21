<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container admin-shell">
    <?php require dirname(__DIR__) . '/partials/nav.php'; ?>
    <header class="page-header">
        <h1 class="page-title">Pedido #<?= $e((string) $order['id']) ?></h1>
        <p class="page-subtitle"><a href="<?= $e($indexUrl) ?>">Volver al listado</a> · <a href="<?= $e($buyerUrl) ?>">Buyer</a></p>
    </header>
    <div class="admin-detail-grid">
        <section class="admin-panel">
            <h2>Pedido</h2>
            <dl class="admin-dl">
                <dt>Estado</dt><dd><?= $e(\App\Core\Layout::statusLabel((string) $order['status'])) ?></dd>
                <dt>Subtotal</dt><dd><?= $e(\App\Core\Layout::formatPrice($order['subtotal_amount'], $order['currency'])) ?></dd>
                <dt>Total</dt><dd><?= $e(\App\Core\Layout::formatPrice($order['total_amount'], $order['currency'])) ?></dd>
                <dt>Buyer</dt><dd><?= $e($order['buyer_username'] ?? '') ?> · <?= $e($order['buyer_email']) ?></dd>
                <dt>Creado</dt><dd><?= $e($order['created_at']) ?></dd>
                <dt>Actualizado</dt><dd><?= $e($order['updated_at']) ?></dd>
            </dl>
        </section>
        <section class="admin-panel">
            <h2>Pago</h2>
            <?php if (empty($order['payment'])): ?>
                <p class="muted">Sin pago.</p>
            <?php else: ?>
                <dl class="admin-dl">
                    <dt>Provider</dt><dd><?= $e($order['payment']['provider']) ?></dd>
                    <dt>External ID</dt><dd><?= $e($order['payment']['external_id'] ?? '') ?></dd>
                    <dt>Estado</dt><dd><?= $e($order['payment']['status']) ?></dd>
                    <dt>Importe</dt><dd><?= $e(\App\Core\Layout::formatPrice($order['payment']['amount'], $order['payment']['currency'])) ?></dd>
                    <dt>Pagado</dt><dd><?= $e($order['payment']['paid_at'] ?? '—') ?></dd>
                    <dt>Creado</dt><dd><?= $e($order['payment']['created_at']) ?></dd>
                </dl>
            <?php endif; ?>
        </section>
    </div>
    <section class="admin-section">
        <h2 class="admin-section-title">Ítems</h2>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Listing snapshot</th>
                        <th>Seller</th>
                        <th>Cantidad</th>
                        <th>Total</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order['items'] as $item): ?>
                        <tr>
                            <td><?= $e($item['title_snapshot']) ?></td>
                            <td><?= $e($item['seller_username']) ?></td>
                            <td><?= $e((string) $item['quantity']) ?></td>
                            <td><?= $e(\App\Core\Layout::formatPrice($item['total_amount'], $item['currency'])) ?></td>
                            <td><?= $e(\App\Core\Layout::statusLabel((string) $item['status'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php
\App\Core\Layout::render('Admin pedido - ERONYX', (string) ob_get_clean());
