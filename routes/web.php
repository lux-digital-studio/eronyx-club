<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\AuthController;
use App\Controllers\AccountController;
use App\Controllers\AdminController;
use App\Controllers\CheckoutController;
use App\Controllers\CreatorApplicationController;
use App\Controllers\CreatorController;
use App\Controllers\CreatorListingController;
use App\Controllers\CreatorMediaController;
use App\Controllers\FavoriteController;
use App\Controllers\MarketplaceController;
use App\Controllers\MediaController;
use App\Controllers\ModeratorCreatorApplicationController;
use App\Controllers\ModeratorController;
use App\Controllers\ModeratorListingController;
use App\Controllers\OrderController;
use App\Controllers\ProfileController;
use App\Controllers\PublicCreatorController;
use App\Core\Router;
use App\Middleware\ActiveCreatorMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\RoleMiddleware;

return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/marketplace', [MarketplaceController::class, 'index']);
    $router->get('/marketplace/{slug}', [MarketplaceController::class, 'show']);
    $router->get('/media/{id}', [MediaController::class, 'show']);
    $router->get('/account', [AccountController::class, 'index'], [AuthMiddleware::class]);
    $router->get('/account/favorites', [FavoriteController::class, 'index'], [AuthMiddleware::class]);
    $router->post('/favorites/{listingId}', [FavoriteController::class, 'store'], [AuthMiddleware::class]);
    $router->post('/favorites/{listingId}/delete', [FavoriteController::class, 'destroy'], [AuthMiddleware::class]);
    $router->get('/account/orders', [OrderController::class, 'index'], [AuthMiddleware::class]);
    $router->get('/account/orders/{id}', [OrderController::class, 'show'], [AuthMiddleware::class]);
    $router->post('/account/orders/{id}/test-pay', [OrderController::class, 'testPay'], [AuthMiddleware::class]);
    $router->get('/account/profile', [ProfileController::class, 'edit'], [AuthMiddleware::class]);
    $router->post('/account/profile', [ProfileController::class, 'update'], [AuthMiddleware::class]);
    $router->post('/account/profile/avatar', [ProfileController::class, 'uploadAvatar'], [AuthMiddleware::class]);
    $router->post('/account/profile/avatar/delete', [ProfileController::class, 'deleteAvatar'], [AuthMiddleware::class]);
    $router->get('/account/creator/apply', [CreatorApplicationController::class, 'showApply'], [AuthMiddleware::class]);
    $router->post('/account/creator/apply', [CreatorApplicationController::class, 'apply'], [AuthMiddleware::class]);
    $router->get('/account/creator/status', [CreatorApplicationController::class, 'status'], [AuthMiddleware::class]);
    $router->get('/checkout/{listingId}', [CheckoutController::class, 'show'], [AuthMiddleware::class]);
    $router->post('/checkout/{listingId}', [CheckoutController::class, 'store'], [AuthMiddleware::class]);
    $router->get('/creator', [CreatorController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
    ]);
    $router->get('/creator/listings', [CreatorListingController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
    ]);
    $router->get('/creator/listings/create', [CreatorListingController::class, 'create'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
    ]);
    $router->post('/creator/listings', [CreatorListingController::class, 'store'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
    ]);
    $router->get('/creator/listings/{id}', [CreatorListingController::class, 'show'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
    ]);
    $router->get('/creator/listings/{id}/edit', [CreatorListingController::class, 'edit'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
    ]);
    $router->post('/creator/listings/{id}', [CreatorListingController::class, 'update'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
    ]);
    $router->post('/creator/listings/{id}/submit', [CreatorListingController::class, 'submit'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
    ]);
    $router->get('/creator/listings/{id}/media', [CreatorMediaController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
    ]);
    $router->post('/creator/listings/{id}/media', [CreatorMediaController::class, 'store'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
    ]);
    $router->post('/creator/listings/{id}/media/{mediaId}/cover', [CreatorMediaController::class, 'setCover'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
    ]);
    $router->post('/creator/listings/{id}/media/{mediaId}/delete', [CreatorMediaController::class, 'destroy'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
    ]);
    $router->get('/creator/{username}', [PublicCreatorController::class, 'show']);
    $router->get('/moderator', [ModeratorController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->get('/moderator/listings', [ModeratorListingController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->get('/moderator/creator-applications', [ModeratorCreatorApplicationController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->get('/moderator/creator-applications/{id}', [ModeratorCreatorApplicationController::class, 'show'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->post('/moderator/creator-applications/{id}/approve', [ModeratorCreatorApplicationController::class, 'approve'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->post('/moderator/creator-applications/{id}/reject', [ModeratorCreatorApplicationController::class, 'reject'], [
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
