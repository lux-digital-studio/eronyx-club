<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\AuthController;
use App\Controllers\AccountController;
use App\Controllers\AdminController;
use App\Controllers\CreatorController;
use App\Controllers\ModeratorController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\RoleMiddleware;

return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/marketplace', [HomeController::class, 'marketplace']);
    $router->get('/account', [AccountController::class, 'index'], [AuthMiddleware::class]);
    $router->get('/creator', [CreatorController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
    ]);
    $router->get('/moderator', [ModeratorController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->get('/admin', [AdminController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/register', [AuthController::class, 'showRegister'], [GuestMiddleware::class]);
    $router->post('/register', [AuthController::class, 'register'], [GuestMiddleware::class]);
    $router->get('/login', [AuthController::class, 'showLogin'], [GuestMiddleware::class]);
    $router->post('/login', [AuthController::class, 'login'], [GuestMiddleware::class]);
    $router->post('/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]);
};
