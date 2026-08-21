<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container legal-doc">
    <?php require __DIR__ . '/partials/banner.php'; ?>
    <article>
        <header class="page-header">
            <h1 class="page-title">Política de privacidad</h1>
            <p class="page-subtitle">Versión <?= $e($legal['privacy_version'] ?? '') ?></p>
        </header>
        <h2>1. Responsable</h2>
        <p>Responsable del tratamiento (placeholder): <?= $e($legal['business_name'] ?? '') ?>. Privacidad: <?= $e($legal['privacy_email'] ?? '') ?>.</p>
        <h2>2. Datos que trata ERONYX</h2>
        <ul>
            <li>Cuenta: email, hash de contraseña, estado, verificación de email, último acceso.</li>
            <li>Perfil: nombre público, usuario, bio, avatar.</li>
            <li>Mensajes y conversaciones entre usuarios.</li>
            <li>Listings, categorías y metadatos de media (no se describen proveedores de almacenamiento externos inexistentes).</li>
            <li>Metadatos de verificación de edad (estado, método, proveedor si existe). No se almacenan documentos de identidad, biometría ni fecha de nacimiento completa.</li>
            <li>Pedidos, ítems y metadatos de pago no sensibles.</li>
            <li>Reportes y acciones de moderación.</li>
            <li>Registros de seguridad y auditoría operativa.</li>
            <li>Consentimientos de documentos legales (tipo, versión, fecha).</li>
        </ul>
        <h2>3. Finalidades</h2>
        <p>Prestar el servicio, autenticar usuarios, moderar contenido, prevenir abuso, cumplir obligaciones de seguridad y, cuando existan, ejecutar comercio. Las bases jurídicas (contrato, interés legítimo, obligación legal, consentimiento) requieren revisión profesional y no se detallan aquí como conclusión jurídica.</p>
        <h2>4. Conservación</h2>
        <p>Los plazos exactos de conservación legal están pendientes de revisión. Operativamente se conservan mientras la cuenta exista y los plazos de seguridad/moderación lo justifiquen. No se afirman retenciones legales específicas.</p>
        <h2>5. Encargados y transferencias</h2>
        <p>No se afirman proveedores externos, analítica ni transferencias internacionales concretas mientras no estén contratados. Si se incorporan, se actualizará esta política.</p>
        <h2>6. Derechos RGPD</h2>
        <p>Según la normativa aplicable, puedes solicitar acceso, rectificación, supresión, limitación, oposición y portabilidad, y reclamar ante la autoridad de control. El procedimiento interno de atención está pendiente de revisión.</p>
        <h2>7. Menores</h2>
        <p>ERONYX no está dirigido a menores de 18 años. No se permite el registro de menores.</p>
        <h2>8. Seguridad</h2>
        <p>Se aplican medidas técnicas razonables (sesiones, CSRF, control de acceso, hashing de contraseñas). Ninguna medida es infalible.</p>
        <h2>9. Cambios</h2>
        <p>Esta política puede cambiar. Las versiones se identifican en config/legal. Una fase posterior podrá exigir reaceptación.</p>
    </article>
</div>
<?php
\App\Core\Layout::render($title ?? 'Privacidad - ERONYX', (string) ob_get_clean());
