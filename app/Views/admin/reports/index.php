<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filters = $filters ?? [];
ob_start();
?>
<div class="container admin-shell">
    <?php require dirname(__DIR__) . '/partials/nav.php'; ?>
    <header class="page-header">
        <h1 class="page-title">Reportes</h1>
        <p class="page-subtitle"><?= $e((string) $total) ?> resultados. Solo lectura.</p>
    </header>
    <form class="admin-filters filter-form" method="get" action="<?= $e($indexUrl) ?>">
        <div class="form-group">
            <label for="status">Estado</label>
            <select id="status" name="status">
                <option value="">Todos</option>
                <?php foreach (['open', 'in_review', 'resolved', 'dismissed'] as $value): ?>
                    <option value="<?= $e($value) ?>"<?= ($filters['status'] ?? '') === $value ? ' selected' : '' ?>><?= $e(\App\Core\Layout::statusLabel($value)) ?></option>
                <?php endforeach; ?>
            </select>
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
        <div class="empty-state"><p>No hay reportes con esos filtros.</p></div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tipo</th>
                        <th>Motivo</th>
                        <th>Estado</th>
                        <th>Reporter</th>
                        <th>Creado</th>
                        <th>Resuelto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $report): ?>
                        <tr>
                            <td><a href="<?= $e($indexUrl . '/' . $report['id']) ?>"><?= $e((string) $report['id']) ?></a></td>
                            <td><?= $e(\App\Core\Layout::reportTargetLabel((string) $report['target_type'])) ?></td>
                            <td><?= $e(\App\Core\Layout::reportReasonLabel((string) $report['reason_code'])) ?></td>
                            <td><span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $report['status'])) ?>"><?= $e(\App\Core\Layout::statusLabel((string) $report['status'])) ?></span></td>
                            <td><?= $e($report['reporter_username'] ?? '') ?></td>
                            <td><?= $e($report['created_at']) ?></td>
                            <td><?= $e($report['resolved_at'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php require dirname(__DIR__) . '/partials/pagination.php'; ?>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Admin reportes - ERONYX', (string) ob_get_clean());
