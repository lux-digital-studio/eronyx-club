<?php

declare(strict_types=1);

/** @var list<array{href: string, label: string, key: string}> $adminNav */
/** @var string $activeNav */

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<nav class="admin-subnav" aria-label="Administración">
    <ul class="admin-subnav-list">
        <?php foreach ($adminNav as $item): ?>
            <li>
                <a class="admin-subnav-link<?= ($activeNav ?? '') === $item['key'] ? ' is-active' : '' ?>" href="<?= $e($item['href']) ?>">
                    <?= $e($item['label']) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
