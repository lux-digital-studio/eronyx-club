<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filters = $filters ?? [];
ob_start();
?>
<div class="container admin-shell">
    <?php require dirname(__DIR__) . '/partials/nav.php'; ?>
    <header class="page-header">
        <h1 class="page-title">Pedidos</h1>
        <p class="page-subtitle"><?= $e((string) $total) ?> resultados.</p>
    </header>
    <form class="admin-filters filter-form" method="get" action="<?= $e($indexUrl) ?>">
        <div class="form-group">
            <label for="q">Buyer / email</label>
            <input id="q" type="search" name="q" value="<?= $e($filters['q'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="status">Estado</label>
            <select id="status" name="status">
                <option value="">Todos</option>
                <?php foreach (['pending', 'paid', 'completed'] as $value): ?>
                    <option value="<?= $e($value) ?>"<?= ($filters['status'] ?? '') === $value ? ' selected' : '' ?>><?= $e(\App\Core\Layout::statusLabel($value)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="date_from">Desde</label>
            <input id="date_from" type="date" name="date_from" value="<?= $e($filters['date_from'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="date_to">Hasta</label>
            <input id="date_to" type="date" name="date_to" value="<?= $e($filters['date_to'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="sort">Orden</label>
            <select id="sort" name="sort">
                <?php foreach (['newest' => 'Más recientes', 'oldest' => 'Más antiguos', 'status' => 'Estado'] as $value => $label): ?>
                    <option value="<?= $e($value) ?>"<?= ($filters['sort'] ?? 'newest') === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">Filtrar</button>
    </form>
    <?php if ($items === []): ?>
        <div class="empty-state"><h2 class="empty-state-title">Sin resultados</h2><p class="empty-state-copy">No hay pedidos con esos filtros.</p></div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Buyer</th>
                        <th>Estado</th>
                        <th>Total</th>
                        <th>Creado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $order): ?>
                        <tr>
                            <td><a href="<?= $e($indexUrl . '/' . $order['id']) ?>"><?= $e((string) $order['id']) ?></a></td>
                            <td><?= $e($order['buyer_username'] ?? '') ?> · <?= $e($order['buyer_email'] ?? '') ?></td>
                            <td><span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $order['status'])) ?>"><?= $e(\App\Core\Layout::statusLabel((string) $order['status'])) ?></span></td>
                            <td><?= $e(\App\Core\Layout::formatPrice($order['total_amount'], $order['currency'])) ?></td>
                            <td><?= $e($order['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php require dirname(__DIR__) . '/partials/pagination.php'; ?>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Admin pedidos - ERONYX', (string) ob_get_clean());
