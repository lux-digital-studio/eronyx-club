<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <h1>Solicitudes creator</h1>

    <?php if ($applications === []): ?>
        <div class="empty-state">
            <p>No hay solicitudes pendientes.</p>
        </div>
    <?php else: ?>
        <ul>
            <?php foreach ($applications as $application): ?>
                <li>
                    <a href="<?= $e($baseUrl . '/' . $application['id']) ?>">
                        <?= $e($application['display_name']) ?> (@<?= $e($application['username']) ?>)
                    </a>
                    <span class="muted"><?= $e($application['created_at']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render('Solicitudes creator - ERONYX', (string) ob_get_clean());
