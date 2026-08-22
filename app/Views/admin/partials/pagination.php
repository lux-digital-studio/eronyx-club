<?php

declare(strict_types=1);

/** @var int $currentPage */
/** @var int $lastPage */
/** @var callable $pageUrl */

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<nav class="pagination" aria-label="Paginación">
    <?php if ($currentPage > 1): ?>
        <a class="btn btn-ghost" href="<?= $e($pageUrl($currentPage - 1)) ?>">Anterior</a>
    <?php else: ?>
        <span class="pagination-disabled">Anterior</span>
    <?php endif; ?>
    <span aria-current="page">Página <?= $e((string) $currentPage) ?> de <?= $e((string) $lastPage) ?></span>
    <?php if ($currentPage < $lastPage): ?>
        <a class="btn btn-ghost" href="<?= $e($pageUrl($currentPage + 1)) ?>">Siguiente</a>
    <?php else: ?>
        <span class="pagination-disabled">Siguiente</span>
    <?php endif; ?>
</nav>
