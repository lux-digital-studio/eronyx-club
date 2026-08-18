<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$canTestPay = $isLocal && is_array($payment) && $payment['status'] === 'pending';
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Pedido #<?= $e($order['id']) ?></h1>
        <p>
            <span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $order['status'])) ?>">
                <?= $e(\App\Core\Layout::statusLabel((string) $order['status'])) ?>
            </span>
        </p>
        <p class="listing-price"><?= $e(\App\Core\Layout::formatPrice($order['total_amount'], $order['currency'])) ?></p>
    </header>

    <section class="order-section">
        <h2>Artículo</h2>
        <?php foreach ($items as $item): ?>
            <article class="order-card card">
                <div class="card-body">
                    <h3><?= $e($item['title_snapshot']) ?></h3>
                    <p>
                        <span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $item['status'])) ?>">
                            <?= $e(\App\Core\Layout::statusLabel((string) $item['status'])) ?>
                        </span>
                    </p>
                    <p><?= $e(\App\Core\Layout::formatPrice($item['total_amount'], $item['currency'])) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <?php if (is_array($payment)): ?>
        <section class="order-section">
            <h2>Pago</h2>
            <article class="order-card card">
                <div class="card-body">
                    <p>
                        <span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $payment['status'])) ?>">
                            <?= $e(\App\Core\Layout::statusLabel((string) $payment['status'])) ?>
                        </span>
                    </p>
                    <p><?= $e(\App\Core\Layout::formatPrice($payment['amount'], $payment['currency'])) ?></p>
                </div>
            </article>
        </section>
    <?php endif; ?>

    <?php if ($canTestPay): ?>
        <section class="order-section">
            <form method="post" action="<?= $e($payUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-dev" type="submit">Confirmar pago de prueba</button>
            </form>
            <p class="dev-note">Entorno local / prueba. No es un método de pago real.</p>
        </section>
    <?php endif; ?>

    <p><a class="link-muted" href="<?= $e($indexUrl) ?>">Volver a mis pedidos</a></p>
</div>
<?php
\App\Core\Layout::render('Pedido #' . $order['id'] . ' - ERONYX', (string) ob_get_clean());
