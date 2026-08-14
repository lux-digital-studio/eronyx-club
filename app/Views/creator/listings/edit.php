<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$selected = static fn (string $actual, string $expected): string => $actual === $expected ? ' selected' : '';
$checked = static fn (int $id, array $ids): string => in_array($id, $ids, true) ? ' checked' : '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar publicación - ERONYX</title>
</head>
<body>
    <main>
        <h1>Editar publicación</h1>

        <?php if ($errors !== []): ?>
            <div role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= $e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= $e($action) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

            <label>
                Título
                <input type="text" name="title" value="<?= $e($old['title'] ?? '') ?>" required maxlength="180">
            </label>

            <label>
                Descripción
                <textarea name="description" maxlength="5000"><?= $e($old['description'] ?? '') ?></textarea>
            </label>

            <label>
                Tipo
                <select name="listing_type" required>
                    <option value="physical_product"<?= $selected($old['listing_type'] ?? '', 'physical_product') ?>>physical_product</option>
                    <option value="digital_content"<?= $selected($old['listing_type'] ?? '', 'digital_content') ?>>digital_content</option>
                    <option value="service"<?= $selected($old['listing_type'] ?? '', 'service') ?>>service</option>
                    <option value="bundle"<?= $selected($old['listing_type'] ?? '', 'bundle') ?>>bundle</option>
                </select>
            </label>

            <label>
                Precio
                <input type="text" name="price" value="<?= $e($old['price'] ?? '') ?>" required inputmode="decimal">
            </label>

            <label>
                Moneda
                <input type="text" name="currency" value="<?= $e($old['currency'] ?? 'EUR') ?>" required maxlength="3">
            </label>

            <label>
                Visibilidad
                <select name="visibility" required>
                    <option value="public"<?= $selected($old['visibility'] ?? '', 'public') ?>>public</option>
                    <option value="private"<?= $selected($old['visibility'] ?? '', 'private') ?>>private</option>
                    <option value="unlisted"<?= $selected($old['visibility'] ?? '', 'unlisted') ?>>unlisted</option>
                </select>
            </label>

            <fieldset>
                <legend>Categorías</legend>
                <?php foreach ($categories as $category): ?>
                    <label>
                        <input type="checkbox" name="categories[]" value="<?= $e($category['id']) ?>"<?= $checked($category['id'], $selectedCategoryIds) ?>>
                        <?= $e($category['name']) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <button type="submit">Guardar cambios</button>
        </form>

        <p><a href="<?= $e($indexUrl) ?>">Volver</a></p>
    </main>
</body>
</html>
