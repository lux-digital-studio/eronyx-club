<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<div class="container">
    <div class="page-header">
        <h1>Editar perfil</h1>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if ($avatarUrl !== null): ?>
                <p><img class="avatar-preview" src="<?= $e($avatarUrl) ?>" alt="" width="120"></p>
            <?php endif; ?>

            <form method="post" action="<?= $e($action) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

                <div class="form-group">
                    <label for="display_name">Nombre público</label>
                    <input id="display_name" type="text" name="display_name" value="<?= $e($profile['display_name'] ?? '') ?>" required maxlength="100">
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input id="username" type="text" name="username" value="<?= $e($profile['username'] ?? '') ?>" required minlength="3" maxlength="50">
                </div>

                <div class="form-group">
                    <label for="bio">Bio</label>
                    <textarea id="bio" name="bio" maxlength="1000"><?= $e($profile['bio'] ?? '') ?></textarea>
                </div>

                <button class="btn btn-primary" type="submit">Guardar perfil</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="post" action="<?= $e($avatarAction) ?>" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <div class="form-group">
                    <label for="avatar">Avatar</label>
                    <input id="avatar" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
                </div>
                <button class="btn btn-secondary" type="submit">Subir avatar</button>
            </form>

            <?php if ($avatarUrl !== null): ?>
                <form method="post" action="<?= $e($deleteAvatarAction) ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-danger" type="submit">Eliminar avatar</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <p><a class="link-muted" href="<?= $e($accountUrl) ?>">Volver a mi cuenta</a></p>
</div>
<?php
\App\Core\Layout::render('Editar perfil - ERONYX', (string) ob_get_clean());
