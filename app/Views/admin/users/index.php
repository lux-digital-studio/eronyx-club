<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filters = $filters ?? [];
ob_start();
?>
<div class="container admin-shell">
    <?php require dirname(__DIR__) . '/partials/nav.php'; ?>

    <header class="page-header">
        <h1 class="page-title">Usuarios</h1>
        <p class="page-subtitle"><?= $e((string) $total) ?> resultados.</p>
    </header>

    <form class="admin-filters filter-form" method="get" action="<?= $e($indexUrl) ?>">
        <div class="form-group">
            <label for="q">Buscar</label>
            <input id="q" type="search" name="q" value="<?= $e($filters['q'] ?? '') ?>" maxlength="120">
        </div>
        <div class="form-group">
            <label for="status">Estado</label>
            <select id="status" name="status">
                <option value="">Todos</option>
                <?php foreach (['active' => 'Activo', 'suspended' => 'Suspendido', 'banned' => 'Bloqueado'] as $value => $label): ?>
                    <option value="<?= $e($value) ?>"<?= ($filters['status'] ?? '') === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="email_verified">Email</label>
            <select id="email_verified" name="email_verified">
                <option value="">Todos</option>
                <option value="verified"<?= ($filters['email_verified'] ?? '') === 'verified' ? ' selected' : '' ?>>Verificado</option>
                <option value="unverified"<?= ($filters['email_verified'] ?? '') === 'unverified' ? ' selected' : '' ?>>Pendiente</option>
            </select>
        </div>
        <div class="form-group">
            <label for="role">Rol</label>
            <select id="role" name="role">
                <option value="">Todos</option>
                <?php foreach (['buyer', 'creator', 'moderator', 'admin'] as $role): ?>
                    <option value="<?= $e($role) ?>"<?= ($filters['role'] ?? '') === $role ? ' selected' : '' ?>><?= $e($role) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="sort">Orden</label>
            <select id="sort" name="sort">
                <?php foreach (['newest' => 'Más recientes', 'oldest' => 'Más antiguos', 'email_asc' => 'Email A-Z', 'email_desc' => 'Email Z-A', 'status' => 'Estado'] as $value => $label): ?>
                    <option value="<?= $e($value) ?>"<?= ($filters['sort'] ?? 'newest') === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">Filtrar</button>
    </form>

    <?php if ($items === []): ?>
        <div class="empty-state"><h2 class="empty-state-title">Sin resultados</h2><p class="empty-state-copy">No hay usuarios con esos filtros.</p></div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Email</th>
                        <th>Usuario</th>
                        <th>Estado</th>
                        <th>Email verificado</th>
                        <th>Roles</th>
                        <th>Alta</th>
                        <th>Último login</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $user): ?>
                        <tr>
                            <td><a href="<?= $e($indexUrl . '/' . $user['id']) ?>"><?= $e((string) $user['id']) ?></a></td>
                            <td><?= $e($user['email']) ?></td>
                            <td><?= $e($user['username'] ?? '') ?> · <?= $e($user['display_name'] ?? '') ?></td>
                            <td><span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $user['status'])) ?>"><?= $e(\App\Core\Layout::statusLabel((string) $user['status'])) ?></span></td>
                            <td><?= !empty($user['email_verified']) ? 'Sí' : 'No' ?></td>
                            <td><?= $e(implode(', ', $user['roles'] ?? [])) ?></td>
                            <td><?= $e($user['created_at']) ?></td>
                            <td><?= $e($user['last_login_at'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php require dirname(__DIR__) . '/partials/pagination.php'; ?>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Admin usuarios - ERONYX', (string) ob_get_clean());
