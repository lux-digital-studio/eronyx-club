<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$authenticated = \App\Core\Nav::context()['authenticated'];
ob_start();
?>
<div class="container home-hero hero">
    <p class="hero-kicker">Marketplace 18+</p>
    <h1>ERONYX</h1>
    <p>Plataforma privada que conecta creators adultos con buyers. Explora publicaciones, desbloquea contenido digital y gestiona tu cuenta con control y discreción.</p>
    <div class="stack">
        <a class="btn btn-primary" href="<?= $e(\App\Core\Layout::url('/marketplace')) ?>">Explorar marketplace</a>
        <?php if ($authenticated): ?>
            <a class="btn btn-ghost" href="<?= $e(\App\Core\Layout::url('/account')) ?>">Mi cuenta</a>
        <?php else: ?>
            <a class="btn btn-secondary" href="<?= $e(\App\Core\Layout::url('/register')) ?>">Crear cuenta</a>
            <a class="btn btn-ghost" href="<?= $e(\App\Core\Layout::url('/login')) ?>">Iniciar sesión</a>
        <?php endif; ?>
    </div>
</div>

<div class="container">
    <section class="home-features" aria-label="Propuesta">
        <article class="home-feature">
            <h2>Marketplace</h2>
            <p class="muted">Publicaciones de creators independientes: contenido digital, packs y más, según lo que cada creator publique.</p>
        </article>
        <article class="home-feature">
            <h2>Creators</h2>
            <p class="muted">Si quieres vender, solicita acceso creator y publica desde un panel propio. La aprobación es manual.</p>
        </article>
        <article class="home-feature">
            <h2>Privacidad</h2>
            <p class="muted">El contenido privado permanece bloqueado hasta que exista un acceso concedido. No se muestra media privada sin permiso.</p>
        </article>
    </section>

    <section class="home-trust" aria-label="Confianza">
        <article class="home-trust-item">
            <h2>Moderación</h2>
            <p class="muted">Las publicaciones pasan por revisión. Puedes reportar contenido, usuarios o mensajes.</p>
        </article>
        <article class="home-trust-item">
            <h2>Reglas claras</h2>
            <p class="muted">Términos, privacidad, política de contenido y mayoría de edad están publicados y versionados.</p>
        </article>
        <article class="home-trust-item">
            <h2>Cuenta tuya</h2>
            <p class="muted">Gestiona perfil, pedidos, favoritos, mensajes y consentimientos desde un único espacio.</p>
        </article>
    </section>

    <aside class="home-notice" role="note">
        <p><strong>Solo para mayores de 18 años.</strong> ERONYX es una plataforma adulta. Al entrar confirmas que tienes la edad legal exigida.</p>
    </aside>

    <ul class="home-legal">
        <li><a href="<?= $e(\App\Core\Layout::url('/legal')) ?>">Información legal</a></li>
        <li><a href="<?= $e(\App\Core\Layout::url('/terms')) ?>">Términos</a></li>
        <li><a href="<?= $e(\App\Core\Layout::url('/privacy')) ?>">Privacidad</a></li>
        <li><a href="<?= $e(\App\Core\Layout::url('/age-policy')) ?>">Mayoría de edad</a></li>
        <li><a href="<?= $e(\App\Core\Layout::url('/reporting-policy')) ?>">Reportar</a></li>
    </ul>
</div>
<?php
\App\Core\Layout::render('ERONYX — Private Marketplace', (string) ob_get_clean());
