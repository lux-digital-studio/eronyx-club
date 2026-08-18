<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mi cuenta - ERONYX</title>
</head>
<body>
    <main>
        <h1>ERONYX - Mi cuenta</h1>
        <p>Sesion iniciada correctamente.</p>
        <p><a href="<?= $e($profileUrl) ?>">Editar perfil</a></p>
        <p><a href="<?= $e($ordersUrl) ?>">Mis pedidos</a></p>
        <p><a href="<?= $e($creatorStatusUrl) ?>">Estado creator</a></p>

        <form method="post" action="<?= $e($logoutUrl) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button type="submit">Cerrar sesion</button>
        </form>
    </main>
</body>
</html>
