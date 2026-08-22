<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$labels = [
    'terms' => 'Términos de uso',
    'privacy' => 'Privacidad',
    'creator_rules' => 'Reglas para creators',
    'content_policy' => 'Política de contenido',
    'age_declaration' => 'Declaración de mayoría de edad',
];
ob_start();
?>
<div class="container">
    <header class="page-header">
        <h1 class="page-title">Legal y consentimientos</h1>
        <p class="page-subtitle">Resumen de documentos aceptados en tu cuenta. Una fase posterior podrá exigir reaceptación si cambia la versión vigente.</p>
    </header>

    <section class="form-section">
        <h2>Versión vigente</h2>
        <dl class="admin-dl">
            <dt>Términos</dt><dd><?= $e($versions['terms'] ?? '') ?><?= !empty($current['terms']) ? ' (aceptada)' : ' (pendiente de reaceptación futura)' ?></dd>
            <dt>Privacidad</dt><dd><?= $e($versions['privacy'] ?? '') ?><?= !empty($current['privacy']) ? ' (aceptada)' : ' (pendiente de reaceptación futura)' ?></dd>
            <dt>Reglas creator</dt><dd><?= $e($versions['creator_rules'] ?? '') ?><?= !empty($current['creator_rules']) ? ' (aceptada)' : ' (no aplica o pendiente)' ?></dd>
            <dt>Política de contenido</dt><dd><?= $e($versions['content_policy'] ?? '') ?><?= !empty($current['content_policy']) ? ' (aceptada)' : ' (no aplica o pendiente)' ?></dd>
            <dt>Edad</dt><dd><?= $e($versions['age_declaration'] ?? '') ?><?= !empty($current['age_declaration']) ? ' (aceptada)' : ' (pendiente de reaceptación futura)' ?></dd>
        </dl>
        <p>
            <a href="<?= $e($termsUrl) ?>">Términos</a> ·
            <a href="<?= $e($privacyUrl) ?>">Privacidad</a> ·
            <a href="<?= $e($creatorRulesUrl) ?>">Reglas creator</a> ·
            <a href="<?= $e($contentPolicyUrl) ?>">Contenido</a> ·
            <a href="<?= $e($agePolicyUrl) ?>">Edad</a>
        </p>
    </section>

    <section class="form-section">
        <h2>Historial</h2>
        <?php if (($consents ?? []) === []): ?>
            <p class="muted">No hay consentimientos registrados.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table consent-table">
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Versión</th>
                            <th>Aceptado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($consents as $consent): ?>
                            <tr>
                                <td><?= $e($labels[$consent['consent_type']] ?? $consent['consent_type']) ?></td>
                                <td><?= $e($consent['document_version']) ?></td>
                                <td><?= $e($consent['accepted_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <p><a class="link-muted" href="<?= $e($accountUrl) ?>">Volver a mi cuenta</a></p>
</div>
<?php
\App\Core\Layout::render('Legal y consentimientos - ERONYX', (string) ob_get_clean());
