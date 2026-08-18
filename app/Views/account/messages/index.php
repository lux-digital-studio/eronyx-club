<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$truncate = static function (string $value, int $max = 120) use ($e): string {
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

    if ($length <= $max) {
        return $e($value);
    }

    $cut = function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);

    return $e($cut) . '…';
};
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Mensajes</h1>
        <p class="page-subtitle">
            Conversaciones con creators y buyers de ERONYX.
            <?php if (($unreadCount ?? 0) > 0): ?>
                Tienes <?= $e((string) $unreadCount) ?> conversación(es) sin leer.
            <?php endif; ?>
        </p>
    </header>

    <?php if ($conversations === []): ?>
        <div class="empty-state">
            <p>No hay conversaciones todavía.</p>
            <p><a class="btn btn-ghost" href="<?= $e($marketplaceUrl) ?>">Ir al marketplace</a></p>
        </div>
    <?php else: ?>
        <ul class="message-inbox">
            <?php foreach ($conversations as $conversation): ?>
                <?php
                $unread = (int) ($conversation['unread_count'] ?? 0) > 0;
                $when = (string) ($conversation['last_message_at'] ?? $conversation['created_at'] ?? '');
                $listingTitle = is_string($conversation['listing_title'] ?? null) ? $conversation['listing_title'] : null;
                $listingSlug = is_string($conversation['listing_slug'] ?? null) ? $conversation['listing_slug'] : null;
                $lastBody = is_string($conversation['last_message_body'] ?? null) ? $conversation['last_message_body'] : '';
                ?>
                <li>
                    <a
                        class="message-thread-card<?= $unread ? ' is-unread' : '' ?>"
                        href="<?= $e($threadBaseUrl . '/' . $conversation['id']) ?>"
                    >
                        <div class="message-thread-card-main">
                            <h2 class="message-thread-name"><?= $e($conversation['other_display_name']) ?></h2>
                            <?php if ($listingTitle !== null && $listingSlug !== null): ?>
                                <p class="message-thread-listing"><?= $e($listingTitle) ?></p>
                            <?php elseif (($conversation['listing_id'] ?? null) !== null): ?>
                                <p class="message-thread-listing muted">Publicación no disponible</p>
                            <?php endif; ?>
                            <?php if ($lastBody !== ''): ?>
                                <p class="message-thread-preview"><?= $truncate($lastBody) ?></p>
                            <?php else: ?>
                                <p class="message-thread-preview muted">Sin mensajes todavía</p>
                            <?php endif; ?>
                        </div>
                        <div class="message-thread-card-meta">
                            <?php if ($when !== ''): ?>
                                <time datetime="<?= $e($when) ?>"><?= $e($when) ?></time>
                            <?php endif; ?>
                            <?php if ($unread): ?>
                                <span class="badge badge-pending">No leído</span>
                            <?php endif; ?>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p><a class="link-muted" href="<?= $e($accountUrl) ?>">Volver a mi cuenta</a></p>
</div>
<?php
\App\Core\Layout::render('Mensajes - ERONYX', (string) ob_get_clean());
