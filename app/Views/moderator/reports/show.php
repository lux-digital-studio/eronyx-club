<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$listing = $target['listing'] ?? null;
$message = $target['message'] ?? null;
$status = (string) ($report['status'] ?? '');
$listingStatus = is_array($listing) ? (string) ($listing['status'] ?? '') : '';
$creatorStatus = is_string($target['creator_status'] ?? null) ? $target['creator_status'] : null;
ob_start();
?>
<div class="container">
    <article class="report-detail">
        <header class="page-header">
            <div class="listing-meta">
                <span class="<?= $e(\App\Core\Layout::statusBadgeClass($status)) ?>">
                    <?= $e(\App\Core\Layout::statusLabel($status)) ?>
                </span>
                <span class="badge"><?= $e(\App\Core\Layout::reportTargetLabel((string) $report['target_type'])) ?></span>
            </div>
            <h1 class="page-title">Reporte #<?= $e((string) $report['id']) ?></h1>
            <p class="report-reason"><?= $e(\App\Core\Layout::reportReasonLabel((string) $report['reason_code'])) ?></p>
        </header>

        <dl class="definition-list">
            <dt>Creado</dt>
            <dd><?= $e((string) $report['created_at']) ?></dd>

            <dt>Reportado por</dt>
            <dd>
                <?= $e((string) ($reporter['display_name'] ?? 'Usuario')) ?>
                <?php if (!empty($reporter['username'])): ?>
                    (@<?= $e((string) $reporter['username']) ?>)
                <?php endif; ?>
            </dd>

            <dt>Detalles</dt>
            <dd><?= $e((string) ($report['details'] ?? '')) ?: 'Sin detalles' ?></dd>

            <dt>Objetivo</dt>
            <dd>
                <?php if (empty($target['available'])): ?>
                    Recurso no disponible
                <?php elseif (is_array($listing)): ?>
                    <?= $e((string) $listing['title']) ?>
                    · <?= $e((string) $listing['slug']) ?>
                    · <?= $e(\App\Core\Layout::statusLabel((string) $listing['status'])) ?>
                <?php elseif (is_array($message)): ?>
                    <p class="message-bubble-body"><?= $e((string) $message['body']) ?></p>
                    <p class="muted">
                        Enviado <?= $e((string) $message['created_at']) ?>
                        · conversación #<?= $e((string) $message['conversation_id']) ?>
                    </p>
                <?php else: ?>
                    <?= $e((string) ($target['label'] ?? 'Usuario')) ?>
                    <?php if (!empty($target['username'])): ?>
                        (@<?= $e((string) $target['username']) ?>)
                    <?php endif; ?>
                    <?php if ($creatorStatus !== null): ?>
                        · creator: <?= $e(\App\Core\Layout::statusLabel($creatorStatus)) ?>
                    <?php endif; ?>
                <?php endif; ?>
            </dd>

            <?php if (!empty($report['resolved_at'])): ?>
                <dt>Cerrado</dt>
                <dd><?= $e((string) $report['resolved_at']) ?></dd>
            <?php endif; ?>
        </dl>

        <?php if (in_array($status, ['open', 'in_review'], true)): ?>
            <div class="moderation-actions">
                <?php if ($status === 'open'): ?>
                    <form method="post" action="<?= $e($reviewUrl) ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn btn-secondary" type="submit">Marcar en revisión</button>
                    </form>
                <?php endif; ?>
                <form method="post" action="<?= $e($resolveUrl) ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-primary" type="submit">Resolver</button>
                </form>
                <form method="post" action="<?= $e($dismissUrl) ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-ghost" type="submit">Descartar</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($suspendListingUrl !== null || $restoreListingUrl !== null): ?>
            <div class="moderation-actions">
                <?php if ($listingStatus !== 'suspended' && $suspendListingUrl !== null): ?>
                    <form method="post" action="<?= $e($suspendListingUrl) ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn btn-danger" type="submit">Suspender publicación</button>
                    </form>
                <?php endif; ?>
                <?php if ($listingStatus === 'suspended' && $restoreListingUrl !== null): ?>
                    <form method="post" action="<?= $e($restoreListingUrl) ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn btn-secondary" type="submit">Restaurar publicación</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($suspendCreatorUrl !== null || $restoreCreatorUrl !== null): ?>
            <div class="moderation-actions">
                <?php if ($creatorStatus === 'active' && $suspendCreatorUrl !== null): ?>
                    <form method="post" action="<?= $e($suspendCreatorUrl) ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn btn-danger" type="submit">Suspender creator</button>
                    </form>
                <?php endif; ?>
                <?php if ($creatorStatus === 'suspended' && $restoreCreatorUrl !== null): ?>
                    <form method="post" action="<?= $e($restoreCreatorUrl) ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn btn-secondary" type="submit">Restaurar creator</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($actions !== []): ?>
            <section class="section">
                <header class="section-header">
                    <h2>Acciones</h2>
                </header>
                <ul class="audit-list">
                    <?php foreach ($actions as $action): ?>
                        <li>
                            <?= $e(\App\Core\Layout::moderationActionLabel((string) $action['action_type'])) ?>
                            <?php if (!empty($action['previous_status'])): ?>
                                · estado previo: <?= $e(\App\Core\Layout::statusLabel((string) $action['previous_status'])) ?>
                            <?php endif; ?>
                            <span class="muted"><?= $e((string) $action['created_at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($audits !== []): ?>
            <section class="section">
                <header class="section-header">
                    <h2>Auditoría</h2>
                </header>
                <ul class="audit-list">
                    <?php foreach ($audits as $audit): ?>
                        <li>
                            <?= $e(\App\Core\Layout::auditEventLabel((string) $audit['event_type'])) ?>
                            <span class="muted"><?= $e((string) $audit['created_at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <p><a class="link-muted" href="<?= $e($indexUrl) ?>">Volver a reportes</a></p>
    </article>
</div>
<?php
\App\Core\Layout::render('Reporte - ERONYX', (string) ob_get_clean());
