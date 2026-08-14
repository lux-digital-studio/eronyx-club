<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - ERONYX</title>
</head>
<body>
    <main>
        <h1>Iniciar sesion</h1>

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
                Email
                <input type="email" name="email" value="<?= $e($old['email'] ?? '') ?>" required maxlength="255" autocomplete="email">
            </label>

            <label>
                Contrasena
                <input type="password" name="password" required autocomplete="current-password">
            </label>

            <button type="submit">Entrar</button>
        </form>

        <p><a href="<?= $e($registerUrl) ?>">Crear cuenta</a></p>
    </main>
</body>
</html>
