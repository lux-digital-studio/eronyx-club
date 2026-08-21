<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container legal-doc">
    <?php require __DIR__ . '/partials/banner.php'; ?>
    <article>
        <header class="page-header">
            <h1 class="page-title">Términos de uso</h1>
            <p class="page-subtitle">Versión <?= $e($legal['terms_version'] ?? '') ?></p>
        </header>
        <h2>1. Qué es ERONYX</h2>
        <p>ERONYX es un marketplace privado que intermedia entre buyers y creators. ERONYX no es el autor de las publicaciones de terceros ni garantiza la legalidad de cada anuncio más allá de sus herramientas de moderación.</p>
        <h2>2. Cuenta</h2>
        <p>Para usar funciones de cuenta debes registrarte, ser mayor de 18 años y mantener datos razonablemente exactos. ERONYX puede suspender cuentas según las reglas de la plataforma.</p>
        <h2>3. Mayoría de edad</h2>
        <p>La plataforma es solo para adultos. La declaración de edad no equivale a una verificación de identidad (KYC). Ver <a href="<?= $e(\App\Core\Layout::url('/age-policy')) ?>">política de mayoría de edad</a>.</p>
        <h2>4. Uso permitido y prohibido</h2>
        <p>Debes cumplir la <a href="<?= $e(\App\Core\Layout::url('/content-policy')) ?>">política de contenido</a>. Está prohibido el contenido ilegal, de menores, no consentido y las infracciones de propiedad intelectual.</p>
        <h2>5. Obligaciones del buyer</h2>
        <p>El buyer usa la plataforma de forma lícita, no elude medidas de seguridad y no revende accesos no autorizados.</p>
        <h2>6. Obligaciones del creator</h2>
        <p>El creator cumple las <a href="<?= $e(\App\Core\Layout::url('/creator-rules')) ?>">reglas para creators</a>, incluida la verificación cuando se exija y los derechos sobre el contenido.</p>
        <h2>7. Publicaciones y moderación</h2>
        <p>ERONYX puede revisar, rechazar, suspender o retirar publicaciones. La moderación no implica vigilancia exhaustiva de todo el catálogo.</p>
        <h2>8. Suspensión de cuentas</h2>
        <p>ERONYX puede suspender o limitar cuentas por incumplimiento, riesgo de seguridad o preocupaciones de edad o consentimiento.</p>
        <h2>9. Propiedad intelectual</h2>
        <p>Quien publica declara tener derechos para hacerlo. Los titulares pueden solicitar retirada según la <a href="<?= $e(\App\Core\Layout::url('/reporting-policy')) ?>">política de reportes</a>. ERONYX no afirma ser un agente DMCA registrado.</p>
        <h2>10. Comercio y pagos</h2>
        <p>Los pagos reales, facturación, impuestos y payouts están pendientes de implementación y de revisión legal. Esta sección no describe un procesador de pagos concreto.</p>
        <h2>11. Limitación de responsabilidad</h2>
        <p>En la medida permitida por la ley aplicable (pendiente de revisión), ERONYX no responde de contenidos de usuarios ni de daños indirectos derivados del uso de la plataforma.</p>
        <h2>12. Cambios</h2>
        <p>Estos términos pueden actualizarse. Una fase posterior podrá exigir reaceptación de versiones nuevas.</p>
        <h2>13. Contacto</h2>
        <p>Operador (placeholder): <?= $e($legal['business_name'] ?? '') ?>. Contacto legal: <?= $e($legal['legal_email'] ?? '') ?>.</p>
        <h2>14. Jurisdicción</h2>
        <p>Ley y fuero aplicables: <?= $e($legal['jurisdiction'] ?? '') ?>. No se indica CIF/NIF ni domicilio fiscal mientras no existan datos verificados.</p>
    </article>
</div>
<?php
\App\Core\Layout::render($title ?? 'Términos de uso - ERONYX', (string) ob_get_clean());
