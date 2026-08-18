<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$selected = static fn (string $actual, string $expected): string => $actual === $expected ? ' selected' : '';
$checked = static fn (int $id, array $ids): string => in_array($id, $ids, true) ? ' checked' : '';
ob_start();
?>
<div class="container">
    <h1>Crear publicación</h1>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= $e($action) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <div class="form-group">
            <label for="title">Título</label>
            <input id="title" type="text" name="title" value="<?= $e($old['title'] ?? '') ?>" required maxlength="180">
        </div>

        <div class="form-group">
            <label for="description">Descripción</label>
            <textarea id="description" name="description" maxlength="5000"><?= $e($old['description'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <label for="listing_type">Tipo</label>
            <select id="listing_type" name="listing_type" required>
                <option value="physical_product"<?= $selected($old['listing_type'] ?? '', 'physical_product') ?>>physical_product</option>
                <option value="digital_content"<?= $selected($old['listing_type'] ?? '', 'digital_content') ?>>digital_content</option>
                <option value="service"<?= $selected($old['listing_type'] ?? '', 'service') ?>>service</option>
                <option value="bundle"<?= $selected($old['listing_type'] ?? '', 'bundle') ?>>bundle</option>
            </select>
        </div>

        <div class="form-group">
            <label for="price">Precio</label>
            <input id="price" type="text" name="price" value="<?= $e($old['price'] ?? '') ?>" required inputmode="decimal">
        </div>

        <div class="form-group">
            <label for="currency">Moneda</label>
            <input id="currency" type="text" name="currency" value="<?= $e($old['currency'] ?? 'EUR') ?>" required maxlength="3">
        </div>

        <div class="form-group">
            <label for="visibility">Visibilidad</label>
            <select id="visibility" name="visibility" required>
                <option value="public"<?= $selected($old['visibility'] ?? '', 'public') ?>>public</option>
                <option value="private"<?= $selected($old['visibility'] ?? '', 'private') ?>>private</option>
                <option value="unlisted"<?= $selected($old['visibility'] ?? '', 'unlisted') ?>>unlisted</option>
            </select>
        </div>

        <fieldset>
            <legend>Categorías</legend>
            <?php foreach ($categories as $category): ?>
                <label>
                    <input type="checkbox" name="categories[]" value="<?= $e($category['id']) ?>"<?= $checked($category['id'], $selectedCategoryIds) ?>>
                    <?= $e($category['name']) ?>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <button class="btn btn-primary" type="submit">Crear publicación</button>
    </form>

    <p><a class="link-muted" href="<?= $e($indexUrl) ?>">Volver</a></p>
</div>
<?php
\App\Core\Layout::render('Crear publicación - ERONYX', (string) ob_get_clean());
