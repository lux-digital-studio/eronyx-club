<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registro - ERONYX</title>
</head>
<body>
    <main>
        <h1>Crear cuenta</h1>

        <?php if ($errors !== []): ?>
            <div role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= $e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= $e($action) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

            <label>
                Nombre publico
                <input type="text" name="display_name" value="<?= $e($old['display_name'] ?? '') ?>" required maxlength="100">
            </label>

            <label>
                Usuario
                <input type="text" name="username" value="<?= $e($old['username'] ?? '') ?>" required maxlength="50" autocomplete="username">
            </label>

            <label>
                Email
                <input type="email" name="email" value="<?= $e($old['email'] ?? '') ?>" required maxlength="255" autocomplete="email">
            </label>

            <label>
                Contrasena
                <input type="password" name="password" required minlength="10" maxlength="255" autocomplete="new-password">
            </label>

            <label>
                Confirmar contrasena
                <input type="password" name="password_confirmation" required minlength="10" maxlength="255" autocomplete="new-password">
            </label>

            <button type="submit">Registrarme</button>
        </form>

        <p><a href="<?= $e($loginUrl) ?>">Ya tengo cuenta</a></p>
    </main>
</body>
</html>
