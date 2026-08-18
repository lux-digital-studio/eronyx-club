<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar perfil - ERONYX</title>
</head>
<body>
    <main>
        <h1>Editar perfil</h1>

        <?php if ($errors !== []): ?>
            <div role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= $e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($avatarUrl !== null): ?>
            <img src="<?= $e($avatarUrl) ?>" alt="" width="120">
        <?php endif; ?>

        <form method="post" action="<?= $e($action) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

            <label>
                Nombre público
                <input type="text" name="display_name" value="<?= $e($profile['display_name'] ?? '') ?>" required maxlength="100">
            </label>

            <label>
                Username
                <input type="text" name="username" value="<?= $e($profile['username'] ?? '') ?>" required minlength="3" maxlength="50">
            </label>

            <label>
                Bio
                <textarea name="bio" maxlength="1000"><?= $e($profile['bio'] ?? '') ?></textarea>
            </label>

            <button type="submit">Guardar perfil</button>
        </form>

        <form method="post" action="<?= $e($avatarAction) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <label>
                Avatar
                <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
            </label>
            <button type="submit">Subir avatar</button>
        </form>

        <?php if ($avatarUrl !== null): ?>
            <form method="post" action="<?= $e($deleteAvatarAction) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button type="submit">Eliminar avatar</button>
            </form>
        <?php endif; ?>

        <p><a href="<?= $e($accountUrl) ?>">Volver a mi cuenta</a></p>
    </main>
</body>
</html>
