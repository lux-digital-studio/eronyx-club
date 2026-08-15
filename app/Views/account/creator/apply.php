<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Solicitar acceso creator - ERONYX</title>
</head>
<body>
    <main>
        <h1>Solicitar acceso creator</h1>

        <?php if ($errors !== []): ?>
            <div role="alert">
                <?php foreach ($errors as $error): ?>
                    <p><?= $e($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= $e($action) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

            <label>
                <input type="checkbox" name="adult_confirmation" value="1">
                Declaro que soy mayor de 18 años.
            </label>

            <label>
                <input type="checkbox" name="terms_confirmation" value="1">
                Acepto solicitar la revisión para acceso creator.
            </label>

            <button type="submit">Enviar solicitud</button>
        </form>

        <p><a href="<?= $e($statusUrl) ?>">Ver estado creator</a></p>
    </main>
</body>
</html>
