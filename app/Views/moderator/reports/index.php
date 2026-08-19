<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Reportes</h1>
        <p class="page-subtitle">Cola de reportes abiertos y en revisión. Los más antiguos primero.</p>
    </header>

    <?php if ($reports === []): ?>
        <div class="empty-state">
            <p>No hay reportes pendientes.</p>
        </div>
    <?php else: ?>
        <ul class="report-queue">
            <?php foreach ($reports as $report): ?>
                <li>
                    <a class="report-card" href="<?= $e($baseUrl . '/' . $report['id']) ?>">
                        <div>
                            <h2 class="report-card-title">
                                <?= $e(\App\Core\Layout::reportTargetLabel((string) $report['target_type'])) ?>
                                ·
                                <?= $e((string) ($report['target']['label'] ?? 'Recurso no disponible')) ?>
                            </h2>
                            <p class="report-reason">
                                <?= $e(\App\Core\Layout::reportReasonLabel((string) $report['reason_code'])) ?>
                            </p>
                            <p class="muted"><?= $e((string) $report['created_at']) ?></p>
                        </div>
                        <span class="<?= $e(\App\Core\Layout::statusBadgeClass((string) $report['status'])) ?>">
                            <?= $e(\App\Core\Layout::statusLabel((string) $report['status'])) ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p><a class="link-muted" href="<?= $e(\App\Core\Layout::url('/moderator')) ?>">Volver a moderación</a></p>
</div>
<?php
\App\Core\Layout::render('Reportes - ERONYX', (string) ob_get_clean());
