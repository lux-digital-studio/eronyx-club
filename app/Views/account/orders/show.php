<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$canTestPay = $isLocal && is_array($payment) && $payment['status'] === 'pending';
ob_start();
?>
<div class="container">
    <div class="page-header">
        <h1>Pedido #<?= $e($order['id']) ?></h1>
        <p>Estado: <?= $e($order['status']) ?></p>
        <p>Total: <?= $e($order['total_amount']) ?> <?= $e($order['currency']) ?></p>
    </div>

    <h2>Items</h2>
    <?php foreach ($items as $item): ?>
        <article class="card">
            <div class="card-body">
                <h3><?= $e($item['title_snapshot']) ?></h3>
                <p><?= $e($item['status']) ?> - <?= $e($item['total_amount']) ?> <?= $e($item['currency']) ?></p>
            </div>
        </article>
    <?php endforeach; ?>

    <?php if (is_array($payment)): ?>
        <h2>Pago</h2>
        <p><?= $e($payment['provider']) ?> - <?= $e($payment['status']) ?> - <?= $e($payment['amount']) ?> <?= $e($payment['currency']) ?></p>
    <?php endif; ?>

    <?php if ($canTestPay): ?>
        <form method="post" action="<?= $e($payUrl) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button class="btn btn-primary" type="submit">Confirmar pago de prueba</button>
        </form>
    <?php endif; ?>

    <p><a class="link-muted" href="<?= $e($indexUrl) ?>">Volver a mis pedidos</a></p>
</div>
<?php
\App\Core\Layout::render('Pedido #' . $order['id'] . ' - ERONYX', (string) ob_get_clean());
