<?php

declare(strict_types=1);

/** @var array{authenticated: bool, csrf: string|null, path: string, showCreator: bool, showModerator: bool, showAdmin: bool, unreadCount: int, notificationUnreadCount: int, openReportCount: int} $nav */

$e = static fn (mixed $value): string => \App\Core\Layout::escape($value);
$url = static fn (string $path): string => \App\Core\Layout::url($path);
$path = $nav['path'];

$active = static function (string $target) use ($path): string {
    $isActive = match ($target) {
        '/' => $path === '/',
        '/marketplace' => $path === '/marketplace' || str_starts_with($path, '/marketplace/'),
        '/account' => $path === '/account' || (
            str_starts_with($path, '/account/')
            && !str_starts_with($path, '/account/messages')
            && !str_starts_with($path, '/account/notifications')
        ),
        '/account/messages' => $path === '/account/messages' || str_starts_with($path, '/account/messages/'),
        '/account/notifications' => $path === '/account/notifications' || str_starts_with($path, '/account/notifications/'),
        '/creator' => $path === '/creator' || str_starts_with($path, '/creator/listings'),
        '/moderator' => $path === '/moderator' || str_starts_with($path, '/moderator/'),
        '/admin' => $path === '/admin' || str_starts_with($path, '/admin/'),
        default => $path === $target,
    };

    return $isActive ? ' aria-current="page"' : '';
};
?>
<header class="site-header">
    <div class="container site-header-inner">
        <a class="brand" href="<?= $e($url('/')) ?>">
            <span class="brand-mark" aria-hidden="true"></span>
            <span class="brand-text">ERONYX</span>
        </a>

        <input type="checkbox" id="nav-toggle" class="nav-toggle-input" autocomplete="off">
        <label class="nav-toggle" for="nav-toggle" aria-controls="site-nav" aria-expanded="false">
            <span class="nav-toggle-bar" aria-hidden="true"></span>
            <span class="nav-toggle-label">Menú</span>
        </label>

        <nav id="site-nav" class="site-nav" aria-label="Principal">
            <div class="nav-links">
                <a class="nav-link" href="<?= $e($url('/marketplace')) ?>"<?= $active('/marketplace') ?>>Marketplace</a>

                <?php if ($nav['authenticated']): ?>
                    <a class="nav-link" href="<?= $e($url('/account/messages')) ?>"<?= $active('/account/messages') ?>>
                        Mensajes<?php if (($nav['unreadCount'] ?? 0) > 0): ?> (<?= $e((string) $nav['unreadCount']) ?>)<?php endif; ?>
                    </a>
                    <a class="nav-link" href="<?= $e($url('/account/notifications')) ?>"<?= $active('/account/notifications') ?>>
                        Notificaciones<?php if (($nav['notificationUnreadCount'] ?? 0) > 0): ?> (<?= $e((string) $nav['notificationUnreadCount']) ?>)<?php endif; ?>
                    </a>
                    <a class="nav-link" href="<?= $e($url('/account')) ?>"<?= $active('/account') ?>>Mi cuenta</a>
                    <?php if ($nav['showCreator']): ?>
                        <a class="nav-link" href="<?= $e($url('/creator')) ?>"<?= $active('/creator') ?>>Creator</a>
                    <?php endif; ?>
                    <?php if ($nav['showModerator']): ?>
                        <a class="nav-link" href="<?= $e($url('/moderator')) ?>"<?= $active('/moderator') ?>>
                            Moderación<?php if (($nav['openReportCount'] ?? 0) > 0): ?><span class="nav-badge"><?= $e((string) $nav['openReportCount']) ?></span><?php endif; ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($nav['showAdmin']): ?>
                        <a class="nav-link" href="<?= $e($url('/admin')) ?>"<?= $active('/admin') ?>>Admin</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="nav-end">
                <?php if ($nav['authenticated']): ?>
                    <form class="nav-logout" method="post" action="<?= $e($url('/logout')) ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($nav['csrf'] ?? '') ?>">
                        <button class="btn btn-ghost" type="submit">Cerrar sesión</button>
                    </form>
                <?php else: ?>
                    <a class="nav-link" href="<?= $e($url('/login')) ?>">Iniciar sesión</a>
                    <a class="btn btn-primary" href="<?= $e($url('/register')) ?>">Crear cuenta</a>
                <?php endif; ?>
            </div>
        </nav>
    </div>
</header>
