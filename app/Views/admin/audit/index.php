<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filters = $filters ?? [];
ob_start();
?>
<div class="container admin-shell">
    <?php require dirname(__DIR__) . '/partials/nav.php'; ?>
    <header class="page-header">
        <h1 class="page-title">Auditoría</h1>
        <p class="page-subtitle"><?= $e((string) $total) ?> eventos. Append-only.</p>
    </header>
    <form class="admin-filters filter-form" method="get" action="<?= $e($indexUrl) ?>">
        <div class="form-group">
            <label for="event_type">Evento</label>
            <select id="event_type" name="event_type">
                <option value="">Todos</option>
                <?php foreach ($eventTypes ?? [] as $event): ?>
                    <option value="<?= $e($event) ?>"<?= ($filters['event_type'] ?? '') === $event ? ' selected' : '' ?>><?= $e(\App\Core\Layout::auditEventLabel((string) $event)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="entity_type">Entidad</label>
            <select id="entity_type" name="entity_type">
                <option value="">Todas</option>
                <?php foreach ($entityTypes ?? [] as $entity): ?>
                    <option value="<?= $e($entity) ?>"<?= ($filters['entity_type'] ?? '') === $entity ? ' selected' : '' ?>><?= $e($entity) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="actor">Actor</label>
            <input id="actor" type="text" name="actor" value="<?= $e($filters['actor'] ?? '') ?>" placeholder="id o username">
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
                <option value="newest"<?= ($filters['sort'] ?? 'newest') === 'newest' ? ' selected' : '' ?>>Más recientes</option>
                <option value="oldest"<?= ($filters['sort'] ?? '') === 'oldest' ? ' selected' : '' ?>>Más antiguos</option>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">Filtrar</button>
    </form>
    <?php if ($items === []): ?>
        <div class="empty-state"><p>No hay eventos con esos filtros.</p></div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Evento</th>
                        <th>Actor</th>
                        <th>Entidad</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $row): ?>
                        <tr>
                            <td><a href="<?= $e($indexUrl . '/' . $row['id']) ?>"><?= $e((string) $row['id']) ?></a></td>
                            <td><?= $e(\App\Core\Layout::auditEventLabel((string) $row['event_type'])) ?></td>
                            <td><?= $e($row['actor_username'] ?? 'sistema') ?></td>
                            <td><?= $e($row['entity_type']) ?> #<?= $e((string) ($row['entity_id'] ?? '')) ?></td>
                            <td><?= $e($row['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php require dirname(__DIR__) . '/partials/pagination.php'; ?>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Admin auditoría - ERONYX', (string) ob_get_clean());
