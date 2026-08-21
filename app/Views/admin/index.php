<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$n = static fn (array $bag, string $key): string => (string) (int) ($bag[$key] ?? 0);
$users = $counts['users'] ?? [];
$creators = $counts['creators'] ?? [];
$listings = $counts['listings'] ?? [];
$orders = $counts['orders'] ?? [];
$reports = $counts['reports'] ?? [];
$auditTotal = (int) (($counts['audit']['total'] ?? 0));
ob_start();
?>
<div class="container admin-shell">
    <?php require __DIR__ . '/partials/nav.php'; ?>

    <header class="page-header">
        <h1 class="page-title">Administración</h1>
        <p class="page-subtitle">Observabilidad operativa de ERONYX.</p>
    </header>

    <?php if (($notice ?? '') !== ''): ?>
        <div class="alert alert-success" role="status"><?= $e($notice) ?></div>
    <?php endif; ?>

    <div class="admin-stat-grid">
        <a class="admin-stat-card" href="<?= $e(\App\Core\Layout::url('/admin/users')) ?>">
            <h2 class="admin-stat-title">Usuarios</h2>
            <p class="admin-stat-value"><?= $e((string) array_sum($users)) ?></p>
            <p class="muted">Activos <?= $e($n($users, 'active')) ?> · Suspendidos <?= $e($n($users, 'suspended')) ?> · Bloqueados <?= $e($n($users, 'banned')) ?></p>
        </a>
        <a class="admin-stat-card" href="<?= $e(\App\Core\Layout::url('/admin/creators')) ?>">
            <h2 class="admin-stat-title">Creators</h2>
            <p class="admin-stat-value"><?= $e((string) array_sum($creators)) ?></p>
            <p class="muted">Activos <?= $e($n($creators, 'active')) ?> · Pendientes <?= $e($n($creators, 'pending')) ?> · Suspendidos <?= $e($n($creators, 'suspended')) ?></p>
        </a>
        <a class="admin-stat-card" href="<?= $e(\App\Core\Layout::url('/admin/listings')) ?>">
            <h2 class="admin-stat-title">Listings</h2>
            <p class="admin-stat-value"><?= $e((string) array_sum($listings)) ?></p>
            <p class="muted">Publicados <?= $e($n($listings, 'published')) ?> · Revisión <?= $e($n($listings, 'pending_review')) ?> · Borrador <?= $e($n($listings, 'draft')) ?></p>
        </a>
        <a class="admin-stat-card" href="<?= $e(\App\Core\Layout::url('/admin/orders')) ?>">
            <h2 class="admin-stat-title">Pedidos</h2>
            <p class="admin-stat-value"><?= $e((string) array_sum($orders)) ?></p>
            <p class="muted">Pendientes <?= $e($n($orders, 'pending')) ?> · Pagados <?= $e($n($orders, 'paid')) ?> · Completados <?= $e($n($orders, 'completed')) ?></p>
        </a>
        <a class="admin-stat-card" href="<?= $e(\App\Core\Layout::url('/admin/reports')) ?>">
            <h2 class="admin-stat-title">Reportes abiertos</h2>
            <p class="admin-stat-value"><?= $e((string) ((int) ($reports['open'] ?? 0) + (int) ($reports['in_review'] ?? 0))) ?></p>
            <p class="muted">Abiertos <?= $e($n($reports, 'open')) ?> · En revisión <?= $e($n($reports, 'in_review')) ?></p>
        </a>
        <a class="admin-stat-card" href="<?= $e(\App\Core\Layout::url('/admin/audit')) ?>">
            <h2 class="admin-stat-title">Auditoría</h2>
            <p class="admin-stat-value"><?= $e((string) $auditTotal) ?></p>
            <p class="muted">Eventos registrados</p>
        </a>
    </div>

    <section class="admin-section">
        <h2 class="admin-section-title">Audit logs recientes</h2>
        <?php if ($recentAudit === []): ?>
            <p class="muted">Todavía no hay eventos de auditoría.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Evento</th>
                            <th>Actor</th>
                            <th>Entidad</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAudit as $row): ?>
                            <tr>
                                <td><a href="<?= $e(\App\Core\Layout::url('/admin/audit/' . $row['id'])) ?>"><?= $e(\App\Core\Layout::auditEventLabel((string) $row['event_type'])) ?></a></td>
                                <td><?= $e($row['actor_username'] ?? 'sistema') ?></td>
                                <td><?= $e($row['entity_type']) ?> #<?= $e((string) ($row['entity_id'] ?? '')) ?></td>
                                <td><?= $e($row['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php
\App\Core\Layout::render('Admin - ERONYX', (string) ob_get_clean());
