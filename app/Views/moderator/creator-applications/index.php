<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Solicitudes creator</h1>
        <p class="page-subtitle">Cola de solicitudes pendientes.</p>
    </header>

    <?php if ($applications === []): ?>
        <div class="empty-state">
            <p>No hay solicitudes pendientes.</p>
        </div>
    <?php else: ?>
        <ul class="queue-list">
            <?php foreach ($applications as $application): ?>
                <li class="queue-item">
                    <div>
                        <strong><?= $e($application['display_name']) ?></strong>
                        <p class="muted">
                            @<?= $e($application['username']) ?>
                            · <?= $e(\App\Core\Layout::verificationMethodLabel((string) ($application['age_method'] ?? ''))) ?>
                            · <?= $e(\App\Core\Layout::statusLabel((string) ($application['age_status'] ?? 'none'))) ?>
                            · <?= $e($application['created_at']) ?>
                        </p>
                    </div>
                    <a class="btn btn-secondary" href="<?= $e($baseUrl . '/' . $application['id']) ?>">Revisar</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Solicitudes creator - ERONYX', (string) ob_get_clean());
