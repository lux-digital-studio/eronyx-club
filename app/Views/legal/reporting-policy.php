<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container legal-doc">
    <?php require __DIR__ . '/partials/banner.php'; ?>
    <article>
        <header class="page-header">
            <h1 class="page-title">Reportes y retirada de contenido</h1>
            <p class="page-subtitle">Versión <?= $e($legal['reporting_policy_version'] ?? '') ?></p>
        </header>
        <p>Puedes reportar publicaciones, usuarios y mensajes desde las fichas correspondientes tras iniciar sesión. No hay un sistema de tickets separado.</p>
        <p><a href="<?= $e(\App\Core\Layout::url('/marketplace')) ?>">Ir al marketplace</a> · <a href="<?= $e(\App\Core\Layout::url('/login')) ?>">Iniciar sesión</a></p>
        <h2>Cómo reportar</h2>
        <p>Usa el botón de reporte en el listing, perfil o conversación. Elige un motivo (spam, estafa, acoso, contenido ilegal, preocupación sobre edad, contenido no consentido, engañoso, artículo prohibido u otro).</p>
        <h2>Revisión</h2>
        <p>La cola de moderación revisa reportes. Posibles acciones: descartar, resolver, suspender publicación, suspender creator o limitar cuentas. No se promete un SLA de tiempo de respuesta.</p>
        <h2>Contenido no consentido</h2>
        <p>Está prohibido y es reportable. Puede haber suspensión inmediata y preservación de evidencia de moderación, con revisión humana. No se automatiza la denuncia.</p>
        <h2>Posible menor</h2>
        <p>Tolerancia cero con menores. El reporte de preocupación sobre edad se trata de forma prioritaria. Puede haber suspensión preventiva. No se hacen acusaciones públicas.</p>
        <h2>Propiedad intelectual / copyright</h2>
        <p>El uploader debe tener derechos. Un titular puede solicitar retirada indicando: identificación del material, prueba de titularidad razonable, datos de contacto y declaración de buena fe. ERONYX no afirma ser agente DMCA registrado. Una política de infracción reiterada es futura.</p>
        <h2>Apelaciones</h2>
        <p>No existe todavía un flujo de apelación formal. Queda indicado como fase posterior.</p>
        <h2>Evidencias</h2>
        <p>Las acciones de moderación y auditoría interna se conservan de forma operativa. No se publican expedientes.</p>
    </article>
</div>
<?php
\App\Core\Layout::render($title ?? 'Reportes y retirada - ERONYX', (string) ob_get_clean());
