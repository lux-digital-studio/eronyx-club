<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container admin-shell">
    <?php require dirname(__DIR__) . '/partials/nav.php'; ?>
    <header class="page-header">
        <h1 class="page-title">Reporte #<?= $e((string) $report['id']) ?></h1>
        <p class="page-subtitle"><a href="<?= $e($indexUrl) ?>">Volver al listado</a></p>
    </header>
    <div class="admin-detail-grid">
        <section class="admin-panel">
            <h2>Reporte</h2>
            <dl class="admin-dl">
                <dt>Estado</dt><dd><?= $e(\App\Core\Layout::statusLabel((string) $report['status'])) ?></dd>
                <dt>Tipo</dt><dd><?= $e(\App\Core\Layout::reportTargetLabel((string) $report['target_type'])) ?></dd>
                <dt>Motivo</dt><dd><?= $e(\App\Core\Layout::reportReasonLabel((string) $report['reason_code'])) ?></dd>
                <dt>Detalles</dt><dd><?= $e($report['details'] ?? '') ?></dd>
                <dt>Reporter</dt><dd><?= $e($report['reporter_username'] ?? '') ?></dd>
                <dt>Creado</dt><dd><?= $e($report['created_at']) ?></dd>
                <dt>Resuelto</dt><dd><?= $e($report['resolved_at'] ?? '—') ?></dd>
            </dl>
        </section>
        <section class="admin-panel">
            <h2>Objetivo</h2>
            <?php
            $target = is_array($report['target'] ?? null) ? $report['target'] : [];
            if ($target === []):
            ?>
                <p class="muted">Sin datos de objetivo.</p>
            <?php else: ?>
                <dl class="admin-dl">
                    <?php foreach ($target as $key => $value): ?>
                        <dt><?= $e((string) $key) ?></dt>
                        <dd><?= $e(is_scalar($value) || $value === null ? (string) ($value ?? '—') : (string) (json_encode($value, JSON_UNESCAPED_UNICODE) ?: '')) ?></dd>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </section>
    </div>
    <section class="admin-section">
        <h2 class="admin-section-title">Acciones de moderación</h2>
        <?php if (empty($report['moderation_actions'])): ?>
            <p class="muted">Sin acciones.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($report['moderation_actions'] as $action): ?>
                    <li><?= $e(\App\Core\Layout::moderationActionLabel((string) $action['action_type'])) ?> · <?= $e($action['created_at']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!empty($showModeratorLinks)): ?>
            <p><a href="<?= $e($moderatorUrl) ?>">Abrir en moderación</a></p>
        <?php endif; ?>
    </section>
</div>
<?php
\App\Core\Layout::render('Admin reporte - ERONYX', (string) ob_get_clean());
