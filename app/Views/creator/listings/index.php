<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mis publicaciones - ERONYX</title>
</head>
<body>
    <main>
        <h1>Mis publicaciones</h1>
        <p><a href="<?= $e($createUrl) ?>">Crear publicación</a></p>

        <?php if ($listings === []): ?>
            <p>No tienes publicaciones todavía.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Estado</th>
                        <th>Tipo</th>
                        <th>Precio</th>
                        <th>Creada</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listings as $listing): ?>
                        <tr>
                            <td><?= $e($listing['title']) ?></td>
                            <td><?= $e($listing['status']) ?></td>
                            <td><?= $e($listing['listing_type']) ?></td>
                            <td><?= $e($listing['price']) ?> <?= $e($listing['currency']) ?></td>
                            <td><?= $e($listing['created_at']) ?></td>
                            <td><a href="<?= $e($baseUrl . '/' . $listing['id']) ?>">Ver</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
</body>
</html>
