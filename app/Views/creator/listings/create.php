<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$selected = static fn (string $actual, string $expected): string => $actual === $expected ? ' selected' : '';
$checked = static fn (int $id, array $ids): string => in_array($id, $ids, true) ? ' checked' : '';
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Crear publicación</h1>
        <p class="page-subtitle">Completa la información, el precio y las categorías.</p>
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

    <form method="post" action="<?= $e($action) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <section class="form-section">
            <h2>Información</h2>
            <div class="form-group">
                <label for="title">Título</label>
                <input id="title" type="text" name="title" value="<?= $e($old['title'] ?? '') ?>" required maxlength="180">
            </div>
            <div class="form-group">
                <label for="description">Descripción</label>
                <textarea id="description" name="description" maxlength="5000"><?= $e($old['description'] ?? '') ?></textarea>
            </div>
        </section>

        <section class="form-section">
            <h2>Tipo</h2>
            <div class="form-group">
                <label for="listing_type">Tipo</label>
                <select id="listing_type" name="listing_type" required>
                    <option value="physical_product"<?= $selected($old['listing_type'] ?? '', 'physical_product') ?>>Producto</option>
                    <option value="digital_content"<?= $selected($old['listing_type'] ?? '', 'digital_content') ?>>Digital</option>
                    <option value="service"<?= $selected($old['listing_type'] ?? '', 'service') ?>>Servicio</option>
                    <option value="bundle"<?= $selected($old['listing_type'] ?? '', 'bundle') ?>>Pack</option>
                </select>
            </div>
        </section>

        <section class="form-section">
            <h2>Precio</h2>
            <div class="form-group">
                <label for="price">Precio</label>
                <input id="price" type="text" name="price" value="<?= $e($old['price'] ?? '') ?>" required inputmode="decimal">
            </div>
            <div class="form-group">
                <label for="currency">Moneda</label>
                <input id="currency" type="text" name="currency" value="<?= $e($old['currency'] ?? 'EUR') ?>" required maxlength="3">
            </div>
        </section>

        <section class="form-section">
            <h2>Visibilidad</h2>
            <div class="form-group">
                <label for="visibility">Visibilidad</label>
                <select id="visibility" name="visibility" required>
                    <option value="public"<?= $selected($old['visibility'] ?? '', 'public') ?>>Público</option>
                    <option value="private"<?= $selected($old['visibility'] ?? '', 'private') ?>>Privado</option>
                    <option value="unlisted"<?= $selected($old['visibility'] ?? '', 'unlisted') ?>>No listado</option>
                </select>
            </div>
        </section>

        <section class="form-section">
            <h2>Categorías</h2>
            <fieldset class="checkbox-list">
                <legend>Categorías</legend>
                <?php foreach ($categories as $category): ?>
                    <label>
                        <input type="checkbox" name="categories[]" value="<?= $e($category['id']) ?>"<?= $checked($category['id'], $selectedCategoryIds) ?>>
                        <?= $e($category['name']) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        </section>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Crear publicación</button>
            <a class="link-muted" href="<?= $e($indexUrl) ?>">Volver</a>
        </div>
    </form>
</div>
<?php
\App\Core\Layout::render('Crear publicación - ERONYX', (string) ob_get_clean());
