<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$otherName = (string) ($conversation['other_display_name'] ?? 'Usuario');
$listingTitle = is_string($conversation['listing_title'] ?? null) ? $conversation['listing_title'] : null;
$listingSlug = is_string($conversation['listing_slug'] ?? null) ? $conversation['listing_slug'] : null;
$closed = ($conversation['status'] ?? '') !== 'active';
ob_start();
?>
<div class="container">
    <header class="page-header message-thread-header">
        <div>
            <h1 class="page-title"><?= $e($otherName) ?></h1>
            <?php if ($listingTitle !== null && $listingSlug !== null): ?>
                <p class="page-subtitle">
                    Sobre
                    <a href="<?= $e($marketplaceUrl . '/' . $listingSlug) ?>"><?= $e($listingTitle) ?></a>
                </p>
            <?php elseif (($conversation['listing_id'] ?? null) !== null): ?>
                <p class="page-subtitle muted">Publicación no disponible</p>
            <?php endif; ?>
        </div>
        <p><a class="link-muted" href="<?= $e($inboxUrl) ?>">Volver a mensajes</a></p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($closed): ?>
        <div class="alert alert-info" role="status">Esta conversación está cerrada.</div>
    <?php elseif (!$canSend): ?>
        <div class="alert alert-info" role="status">No se pueden enviar mensajes porque la otra cuenta ya no está activa.</div>
    <?php endif; ?>

    <section class="message-thread" aria-label="Historial de mensajes">
        <?php if ($messages === []): ?>
            <p class="muted">Todavía no hay mensajes. Escribe el primero.</p>
        <?php else: ?>
            <ol class="message-list">
                <?php foreach ($messages as $message): ?>
                    <?php $isOwn = (int) $message['sender_user_id'] === (int) $currentUserId; ?>
                    <li class="message-row<?= $isOwn ? ' is-own' : ' is-other' ?>">
                        <article class="message-bubble" aria-label="<?= $e($isOwn ? 'Tú' : $otherName) ?>">
                            <p class="message-bubble-meta">
                                <span class="message-bubble-author"><?= $e($isOwn ? 'Tú' : $otherName) ?></span>
                                <?php if (!empty($message['created_at'])): ?>
                                    <time datetime="<?= $e($message['created_at']) ?>"><?= $e($message['created_at']) ?></time>
                                <?php endif; ?>
                            </p>
                            <p class="message-bubble-body"><?= $e($message['body']) ?></p>
                        </article>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <?php if ($canSend): ?>
        <form class="message-compose" method="post" action="<?= $e($sendUrl) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <div class="form-group">
                <label for="body">Mensaje</label>
                <textarea
                    id="body"
                    name="body"
                    rows="4"
                    maxlength="2000"
                    required
                ><?= $e($oldBody) ?></textarea>
            </div>
            <button class="btn btn-primary" type="submit">Enviar mensaje</button>
        </form>
    <?php endif; ?>
</div>
<?php
\App\Core\Layout::render($otherName . ' - Mensajes - ERONYX', (string) ob_get_clean());
