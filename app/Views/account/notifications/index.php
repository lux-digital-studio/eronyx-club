<?php

declare(strict_types=1);

use App\Services\NotificationService;

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageUrl = static function (int $page) use ($indexUrl): string {
    return $page > 1 ? $indexUrl . '?page=' . $page : $indexUrl;
};
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Notificaciones</h1>
        <p class="page-subtitle">
            Avisos de tu cuenta.
            <?php if (($unreadCount ?? 0) > 0): ?>
                Tienes <?= $e((string) $unreadCount) ?> sin leer.
            <?php endif; ?>
        </p>
    </header>

    <?php if (($unreadCount ?? 0) > 0): ?>
        <form method="post" action="<?= $e($readAllUrl) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button class="btn btn-secondary" type="submit">Marcar todas como leídas</button>
        </form>
    <?php endif; ?>

    <?php if ($notifications === []): ?>
        <div class="empty-state">
            <h2 class="empty-state-title">Bandeja vacía</h2>
            <p class="empty-state-copy">No tienes notificaciones todavía.</p>
        </div>
    <?php else: ?>
        <ul class="message-inbox notification-inbox">
            <?php foreach ($notifications as $notification): ?>
                <?php
                $unread = ($notification['read_at'] ?? null) === null;
                $when = (string) ($notification['created_at'] ?? '');
                $actionUrl = NotificationService::safeActionUrl(
                    is_string($notification['action_url'] ?? null) ? $notification['action_url'] : null
                );
                $readUrl = $readBaseUrl . '/' . (int) $notification['id'] . '/read';
                ?>
                <li>
                    <article class="message-thread-card<?= $unread ? ' is-unread' : '' ?>">
                        <div class="message-thread-card-main">
                            <h2 class="message-thread-name"><?= $e($notification['title']) ?></h2>
                            <?php if (!empty($notification['body'])): ?>
                                <p class="message-thread-preview"><?= $e($notification['body']) ?></p>
                            <?php endif; ?>
                            <?php if ($actionUrl !== null): ?>
                                <p class="message-thread-listing">
                                    <a href="<?= $e(\App\Core\Layout::url($actionUrl)) ?>">Ver detalle</a>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="message-thread-card-meta">
                            <?php if ($when !== ''): ?>
                                <time datetime="<?= $e($when) ?>"><?= $e($when) ?></time>
                            <?php endif; ?>
                            <?php if ($unread): ?>
                                <span class="badge badge-pending">No leída</span>
                                <form method="post" action="<?= $e($readUrl) ?>">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <button class="btn btn-ghost" type="submit">Marcar como leída</button>
                                </form>
                            <?php else: ?>
                                <span class="badge badge-published">Leída</span>
                            <?php endif; ?>
                        </div>
                    </article>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <nav class="pagination" aria-label="Paginación">
        <?php if ($currentPage > 1): ?>
            <a class="btn btn-ghost" href="<?= $e($pageUrl($currentPage - 1)) ?>">Anterior</a>
        <?php else: ?>
            <span class="pagination-disabled">Anterior</span>
        <?php endif; ?>

        <span aria-current="page">Página <?= $e((string) $currentPage) ?> de <?= $e((string) $lastPage) ?></span>

        <?php if ($currentPage < $lastPage): ?>
            <a class="btn btn-ghost" href="<?= $e($pageUrl($currentPage + 1)) ?>">Siguiente</a>
        <?php else: ?>
            <span class="pagination-disabled">Siguiente</span>
        <?php endif; ?>
    </nav>

    <p><a class="link-muted" href="<?= $e($accountUrl) ?>">Volver a mi cuenta</a></p>
</div>
<?php
\App\Core\Layout::render('Notificaciones - ERONYX', (string) ob_get_clean());
