<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$renderMeta = static function (mixed $value, callable $escape) use (&$renderMeta): string {
    if (is_array($value)) {
        $html = '<dl class="admin-dl">';
        foreach ($value as $key => $item) {
            $html .= '<dt>' . $escape((string) $key) . '</dt><dd>' . $renderMeta($item, $escape) . '</dd>';
        }

        return $html . '</dl>';
    }

    if (is_bool($value)) {
        return $escape($value ? 'true' : 'false');
    }

    if ($value === null) {
        return $escape('null');
    }

    return $escape((string) $value);
};
ob_start();
?>
<div class="container admin-shell">
    <?php require dirname(__DIR__) . '/partials/nav.php'; ?>
    <header class="page-header">
        <h1 class="page-title">Evento #<?= $e((string) $entry['id']) ?></h1>
        <p class="page-subtitle"><a href="<?= $e($indexUrl) ?>">Volver al listado</a></p>
    </header>
    <section class="admin-panel">
        <dl class="admin-dl">
            <dt>Evento</dt><dd><?= $e(\App\Core\Layout::auditEventLabel((string) $entry['event_type'])) ?></dd>
            <dt>Actor</dt><dd><?= $e($entry['actor_username'] ?? 'sistema') ?></dd>
            <dt>Entidad</dt><dd><?= $e($entry['entity_type']) ?> #<?= $e((string) ($entry['entity_id'] ?? '')) ?></dd>
            <dt>Fecha</dt><dd><?= $e($entry['created_at']) ?></dd>
        </dl>
        <h2>Metadata</h2>
        <?php if (($entry['metadata'] ?? []) === []): ?>
            <p class="muted">Sin metadata.</p>
        <?php else: ?>
            <div class="admin-metadata"><?= $renderMeta($entry['metadata'], $e) ?></div>
        <?php endif; ?>
    </section>
</div>
<?php
\App\Core\Layout::render('Admin auditoría detalle - ERONYX', (string) ob_get_clean());
