<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container legal-doc">
    <?php require __DIR__ . '/partials/banner.php'; ?>
    <article>
        <header class="page-header">
            <h1 class="page-title">Política de contenido</h1>
            <p class="page-subtitle">Versión <?= $e($legal['content_policy_version'] ?? '') ?></p>
        </header>
        <h2>Contenido adulto consentido</h2>
        <p>El contenido adulto solo puede involucrar adultos, con consentimiento válido y con los derechos necesarios para distribuirlo. El hecho de que un contenido sea adulto no implica que sea lícito en todos los territorios.</p>
        <h2>Prohibido</h2>
        <p>Está prohibido, entre otros:</p>
        <ul>
            <li>menores o cualquier representación sexual de menores;</li>
            <li>personas que parezcan menores cuando exista incertidumbre grave;</li>
            <li>contenido no consentido, revenge porn, cámaras ocultas o voyeurismo no consentido;</li>
            <li>coerción, explotación o tráfico sexual;</li>
            <li>zoofilia, necrofilia o violencia sexual no consentida;</li>
            <li>contenido ilegal;</li>
            <li>material robado e infracciones de copyright / propiedad intelectual;</li>
            <li>suplantación dañina y datos personales de terceros sin permiso;</li>
            <li>armas, drogas u otros bienes que ERONYX excluya del catálogo;</li>
            <li>contenido que infrinja futuras políticas de pagos.</li>
        </ul>
        <h2>Moderación</h2>
        <p>ERONYX puede retirar o suspender contenido y cuentas. La revisión es humana en las colas de moderación existentes. No hay automatización de denuncias policiales.</p>
    </article>
</div>
<?php
\App\Core\Layout::render($title ?? 'Política de contenido - ERONYX', (string) ob_get_clean());
