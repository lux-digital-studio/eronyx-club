<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Imágenes - ERONYX</title>
</head>
<body>
    <main>
        <h1>Imágenes de <?= $e($listing['title']) ?></h1>

        <?php if ($error !== null): ?>
            <div role="alert"><?= $e($error) ?></div>
        <?php endif; ?>

        <?php if ($canModify): ?>
            <form method="post" action="<?= $e($action) ?>" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

                <label>
                    Imagen
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
                </label>

                <label>
                    Uso
                    <select name="usage_type" required>
                        <option value="cover">cover</option>
                        <option value="gallery">gallery</option>
                        <option value="preview">preview</option>
                    </select>
                </label>

                <button type="submit">Subir imagen</button>
            </form>
        <?php else: ?>
            <p>Las imágenes no se pueden modificar en el estado actual.</p>
        <?php endif; ?>

        <?php if ($mediaItems === []): ?>
            <p>No hay imágenes asociadas.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Preview</th>
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
                            <td><img src="<?= $e($mediaBaseUrl . '/' . $item['media_file_id']) ?>" alt="" width="120"></td>
                            <td><?= $e($item['usage_type']) ?></td>
                            <td><?= $e($item['mime_type']) ?></td>
                            <td><?= $e($item['size_bytes']) ?></td>
                            <td><?= $e($item['sort_order']) ?></td>
                            <td><?= $e($item['status']) ?></td>
                            <td>
                                <?php if ($canModify): ?>
                                    <?php if ($item['usage_type'] !== 'cover'): ?>
                                        <form method="post" action="<?= $e($action . '/' . $item['media_file_id'] . '/cover') ?>">
                                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                            <button type="submit">Portada</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= $e($action . '/' . $item['media_file_id'] . '/delete') ?>">
                                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                        <button type="submit">Eliminar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p><a href="<?= $e($listingUrl) ?>">Volver al listing</a></p>
    </main>
</body>
</html>
