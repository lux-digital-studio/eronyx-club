<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$security = require dirname(__DIR__, 3) . '/config/security.php';
$sessionName = (string) ($security['session_name'] ?? 'eronyx_session');
ob_start();
?>
<div class="container legal-doc">
    <?php require __DIR__ . '/partials/banner.php'; ?>
    <article>
        <header class="page-header">
            <h1 class="page-title">Política de cookies</h1>
            <p class="page-subtitle">Versión <?= $e($legal['cookies_version'] ?? '') ?></p>
        </header>
        <p>En el estado actual, ERONYX solo usa cookies o almacenamiento equivalente estrictamente necesario para el funcionamiento. No hay cookies de analítica, publicidad ni redes sociales.</p>
        <h2>Cookies esenciales actuales</h2>
        <ul>
            <li><strong><?= $e($sessionName) ?></strong>: cookie de sesión HTTP-only para autenticación y estado de la petición.</li>
            <li>El token CSRF se guarda en la sesión del servidor, no como cookie de marketing.</li>
        </ul>
        <p>Estas cookies son necesarias para iniciar sesión, proteger formularios y mantener la sesión. No se muestra un banner de consentimiento ficticio mientras no existan cookies no esenciales.</p>
        <h2>Futuro</h2>
        <p>Si se añaden cookies de analítica o marketing, se incorporará un mecanismo de consentimiento (CMP/banner) antes de usarlas.</p>
    </article>
</div>
<?php
\App\Core\Layout::render($title ?? 'Cookies - ERONYX', (string) ob_get_clean());
