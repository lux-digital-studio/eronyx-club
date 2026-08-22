<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Imágenes de <?= $e($listing['title']) ?></h1>
        <p class="page-subtitle">Portada, galería, preview y contenido privado.</p>
    </header>

    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php if ($canModify): ?>
        <section class="form-section media-upload">
            <h2>Subir archivo</h2>
            <form method="post" action="<?= $e($action) ?>" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

                <div class="form-group">
                    <label for="image">Archivo</label>
                    <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" required>
                </div>

                <div class="form-group">
                    <label for="usage_type">Uso</label>
                    <select id="usage_type" name="usage_type" required>
                        <option value="cover">Portada</option>
                        <option value="gallery">Galería</option>
                        <option value="preview">Preview</option>
                        <option value="private_content">Contenido privado</option>
                    </select>
                </div>

                <button class="btn btn-primary" type="submit">Subir archivo</button>
            </form>
        </section>
    <?php else: ?>
        <p class="muted">Las imágenes no se pueden modificar en el estado actual.</p>
    <?php endif; ?>

    <?php if ($mediaItems === []): ?>
        <div class="empty-state">
            <p>No hay imágenes asociadas.</p>
        </div>
    <?php else: ?>
        <div class="media-grid media-manager">
            <?php foreach ($mediaItems as $item): ?>
                <article class="media-card<?= $item['media_type'] === 'video' ? ' is-video' : '' ?><?= $item['usage_type'] === 'cover' ? ' is-cover' : '' ?><?= $item['usage_type'] === 'private_content' ? ' is-private' : '' ?>">
                    <div class="media-card-preview">
                        <?php if ($item['media_type'] === 'video'): ?>
                            <video src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>" controls preload="metadata" controlsList="nodownload" width="320"></video>
                        <?php else: ?>
                            <img src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>" alt="<?= $e($listing['title']) ?>" width="240" height="300" loading="lazy" decoding="async">
                        <?php endif; ?>
                    </div>
                    <div class="media-card-body">
                        <p>
                            <span class="badge badge-<?= $e((string) $item['usage_type']) ?>">
                                <?= $e(\App\Core\Layout::usageLabel((string) $item['usage_type'])) ?>
                            </span>
                            <?php if ($item['media_type'] === 'video'): ?>
                                <span class="badge">Vídeo privado</span>
                            <?php endif; ?>
                        </p>
                        <p class="muted">
                            <?= $e($item['mime_type']) ?>
                            · <?= $e(\App\Core\Layout::formatBytes($item['size_bytes'])) ?>
                        </p>
                        <p class="muted"><?= $item['visibility'] === 'private' ? 'Contenido privado' : 'Público' ?></p>
                        <?php if ($canModify): ?>
                            <div class="media-card-actions">
                                <?php if ($item['usage_type'] !== 'cover' && $item['media_type'] === 'image' && $item['visibility'] === 'public'): ?>
                                    <form method="post" action="<?= $e($action . '/' . $item['media_file_id'] . '/cover') ?>">
                                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                        <button class="btn btn-secondary" type="submit">Portada</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= $e($action . '/' . $item['media_file_id'] . '/delete') ?>">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <button class="btn btn-danger" type="submit">Eliminar</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p><a class="link-muted" href="<?= $e($listingUrl) ?>">Volver al listing</a></p>
</div>
<?php
\App\Core\Layout::render('Imágenes - ERONYX', (string) ob_get_clean());
