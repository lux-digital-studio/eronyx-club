<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\AuthController;
use App\Controllers\AccountController;
use App\Controllers\AdminController;
use App\Controllers\CreatorController;
use App\Controllers\CreatorListingController;
use App\Controllers\MarketplaceController;
use App\Controllers\ModeratorController;
use App\Controllers\ModeratorListingController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\RoleMiddleware;

return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/marketplace', [MarketplaceController::class, 'index']);
    $router->get('/marketplace/{slug}', [MarketplaceController::class, 'show']);
    $router->get('/account', [AccountController::class, 'index'], [AuthMiddleware::class]);
    $router->get('/creator', [CreatorController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
    ]);
    $router->get('/creator/listings', [CreatorListingController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
    ]);
    $router->get('/creator/listings/create', [CreatorListingController::class, 'create'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
    ]);
    $router->post('/creator/listings', [CreatorListingController::class, 'store'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
    ]);
    $router->get('/creator/listings/{id}', [CreatorListingController::class, 'show'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
    ]);
    $router->get('/creator/listings/{id}/edit', [CreatorListingController::class, 'edit'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
    ]);
    $router->post('/creator/listings/{id}', [CreatorListingController::class, 'update'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
    ]);
    $router->post('/creator/listings/{id}/submit', [CreatorListingController::class, 'submit'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
    ]);
    $router->get('/moderator', [ModeratorController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->get('/moderator/listings', [ModeratorListingController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->get('/moderator/listings/{id}', [ModeratorListingController::class, 'show'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->post('/moderator/listings/{id}/approve', [ModeratorListingController::class, 'approve'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->post('/moderator/listings/{id}/reject', [ModeratorListingController::class, 'reject'], [
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
