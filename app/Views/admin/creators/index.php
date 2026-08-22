<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filters = $filters ?? [];
ob_start();
?>
<div class="container admin-shell">
    <?php require dirname(__DIR__) . '/partials/nav.php'; ?>
    <header class="page-header">
        <h1 class="page-title">Creators</h1>
        <p class="page-subtitle"><?= $e((string) $total) ?> resultados.</p>
    </header>
    <form class="admin-filters filter-form" method="get" action="<?= $e($indexUrl) ?>">
        <div class="form-group">
            <label for="q">Buscar</label>
            <input id="q" type="search" name="q" value="<?= $e($filters['q'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="status">Estado</label>
            <select id="status" name="status">
                <option value="">Todos</option>
                <?php foreach (['active', 'pending', 'rejected', 'suspended'] as $value): ?>
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
        <div class="empty-state"><h2 class="empty-state-title">Sin resultados</h2><p class="empty-state-copy">No hay creators con esos filtros.</p></div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Usuario</th>
                        <th>Estado creator</th>
                        <th>Email verificado</th>
                        <th>Rol creator</th>
                        <th>Listings</th>
                        <th>Alta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $creator): ?>
                        <tr>
                            <td><a href="<?= $e($indexUrl . '/' . $creator['user_id']) ?>"><?= $e((string) $creator['user_id']) ?></a></td>
                            <td><?= $e($creator['username'] ?? '') ?> · <?= $e($creator['display_name'] ?? '') ?></td>
                            <td><span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $creator['status'])) ?>"><?= $e(\App\Core\Layout::statusLabel((string) $creator['status'])) ?></span></td>
                            <td><?= !empty($creator['email_verified']) ? 'Sí' : 'No' ?></td>
                            <td><?= !empty($creator['has_creator_role']) ? 'Sí' : 'No' ?></td>
                            <td><?= $e((string) $creator['listing_count']) ?></td>
                            <td><?= $e($creator['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php require dirname(__DIR__) . '/partials/pagination.php'; ?>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Admin creators - ERONYX', (string) ob_get_clean());
