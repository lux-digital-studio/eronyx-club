<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$selected = static fn (string $actual, string $expected): string => $actual === $expected ? ' selected' : '';
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Enviar reporte</h1>
        <p class="page-subtitle">
            <?= $e(\App\Core\Layout::reportTargetLabel((string) $context['target_type'])) ?>:
            <?= $e((string) $context['title']) ?>.
            Usa este formulario para informar de un posible problema. Un equipo revisará el aviso.
        </p>
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

    <form class="report-form" method="post" action="<?= $e($action) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <div class="form-group">
            <label for="reason_code">Motivo</label>
            <select id="reason_code" name="reason_code" required>
                <option value="">Selecciona un motivo</option>
                <?php foreach ($reasons as $reason): ?>
                    <option value="<?= $e($reason) ?>"<?= $selected((string) ($old['reason_code'] ?? ''), $reason) ?>>
                        <?= $e(\App\Core\Layout::reportReasonLabel($reason)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="details">Detalles (opcional, obligatorio si eliges Otro)</label>
            <textarea id="details" name="details" rows="5" maxlength="2000"><?= $e($old['details'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Enviar reporte</button>
            <a class="btn btn-ghost" href="<?= $e($cancelUrl) ?>">Cancelar</a>
        </div>
    </form>
</div>
<?php
\App\Core\Layout::render('Enviar reporte - ERONYX', (string) ob_get_clean());
