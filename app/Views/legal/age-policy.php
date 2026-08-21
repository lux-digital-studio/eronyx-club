<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container legal-doc">
    <?php require __DIR__ . '/partials/banner.php'; ?>
    <article>
        <header class="page-header">
            <h1 class="page-title">Política de mayoría de edad</h1>
            <p class="page-subtitle">Versión <?= $e($legal['age_policy_version'] ?? '') ?></p>
        </header>
        <p>ERONYX es una plataforma solo para personas de 18 años o más. No se acepta el registro ni la participación de menores.</p>
        <h2>Creators</h2>
        <p>Los creators requieren verificación de edad según la política técnica vigente (declaración, revisión manual o proveedor). La self-declaration no equivale a KYC. No se describe un proveedor de identidad real inexistente.</p>
        <h2>Incidencias</h2>
        <p>Si hay preocupación de posible menor, el contenido o la cuenta pueden suspenderse de forma preventiva y el caso se revisa. El reporte específico de preocupación sobre edad está disponible en el flujo de reportes.</p>
        <h2>Autoridades</h2>
        <p>El cumplimiento con autoridades se hará según la obligación legal aplicable, pendiente de revisión profesional. No se detallan procedimientos policiales específicos.</p>
    </article>
</div>
<?php
\App\Core\Layout::render($title ?? 'Mayoría de edad - ERONYX', (string) ob_get_clean());
