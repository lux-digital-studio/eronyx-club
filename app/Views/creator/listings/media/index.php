<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <h1>Imágenes de <?= $e($listing['title']) ?></h1>

    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php if ($canModify): ?>
        <form method="post" action="<?= $e($action) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

            <div class="form-group">
                <label for="image">Archivo</label>
                <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" required>
            </div>

            <div class="form-group">
                <label for="usage_type">Uso</label>
                <select id="usage_type" name="usage_type" required>
                    <option value="cover">cover</option>
                    <option value="gallery">gallery</option>
                    <option value="preview">preview</option>
                    <option value="private_content">private_content</option>
                </select>
            </div>

            <button class="btn btn-primary" type="submit">Subir archivo</button>
        </form>
    <?php else: ?>
        <p class="muted">Las imágenes no se pueden modificar en el estado actual.</p>
    <?php endif; ?>

    <?php if ($mediaItems === []): ?>
        <div class="empty-state">
            <p>No hay imágenes asociadas.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Media</th>
                        <th>Alcance</th>
                        <th>Tipo</th>
                        <th>Uso</th>
                        <th>MIME</th>
                        <th>Tamaño</th>
                        <th>Orden</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mediaItems as $item): ?>
                        <tr>
                            <td>
                                <?php if ($item['media_type'] === 'video'): ?>
                                    <video src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>" controls controlsList="nodownload" width="180"></video>
                                <?php else: ?>
                                    <img src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>" alt="" width="120">
                                <?php endif; ?>
                            </td>
                            <td><?= $item['visibility'] === 'private' ? 'Contenido privado' : 'Público' ?></td>
                            <td><?= $e($item['media_type']) ?></td>
                            <td><?= $e($item['usage_type']) ?></td>
                            <td><?= $e($item['mime_type']) ?></td>
                            <td><?= $e($item['size_bytes']) ?></td>
                            <td><?= $e($item['sort_order']) ?></td>
                            <td><?= $e($item['status']) ?></td>
                            <td>
                                <?php if ($canModify): ?>
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
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p><a class="link-muted" href="<?= $e($listingUrl) ?>">Volver al listing</a></p>
</div>
<?php
\App\Core\Layout::render('Imágenes - ERONYX', (string) ob_get_clean());
