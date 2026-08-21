<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container legal-doc">
    <?php require __DIR__ . '/partials/banner.php'; ?>
    <article>
        <header class="page-header">
            <h1 class="page-title">Reglas para creators</h1>
            <p class="page-subtitle">Versión <?= $e($legal['creator_rules_version'] ?? '') ?></p>
        </header>
        <p>Al solicitar acceso creator declaras y te comprometes a:</p>
        <ul>
            <li>tener 18 años o más;</li>
            <li>completar la identidad/verificación válida cuando ERONYX la exija; la declaración no equivale a KYC;</li>
            <li>tener derechos sobre el contenido que publicas;</li>
            <li>obtener consentimiento de las personas que aparecen;</li>
            <li>no subir contenido prohibido según la política de contenido;</li>
            <li>usar descripciones veraces;</li>
            <li>no vender contenido de terceros sin derechos;</li>
            <li>cumplir las decisiones de moderación;</li>
            <li>respetar reportes y retiradas;</li>
            <li>mantener la información de cuenta correcta;</li>
            <li>no intentar eludir la verificación.</li>
        </ul>
        <h2>Terceras personas</h2>
        <p>Si una publicación muestra a otra persona, el creator debe garantizar que es adulta, que hay consentimiento y que existen rights/release cuando corresponda. ERONYX no almacena releases de participantes en esta fase. El upload de autorizaciones es una limitación futura, no un requisito técnico actual.</p>
    </article>
</div>
<?php
\App\Core\Layout::render($title ?? 'Reglas para creators - ERONYX', (string) ob_get_clean());
