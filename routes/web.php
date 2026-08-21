<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\AuthController;
use App\Controllers\LegalController;
use App\Controllers\AccountController;
use App\Controllers\AccountSecurityController;
use App\Controllers\AdminAuditController;
use App\Controllers\AdminController;
use App\Controllers\AdminCreatorController;
use App\Controllers\AdminListingController;
use App\Controllers\AdminOrderController;
use App\Controllers\AdminReportController;
use App\Controllers\AdminUserController;
use App\Controllers\CheckoutController;
use App\Controllers\CreatorApplicationController;
use App\Controllers\CreatorController;
use App\Controllers\CreatorListingController;
use App\Controllers\CreatorMediaController;
use App\Controllers\EmailVerificationController;
use App\Controllers\FavoriteController;
use App\Controllers\MarketplaceController;
use App\Controllers\MediaController;
use App\Controllers\MessageController;
use App\Controllers\NotificationController;
use App\Controllers\ModeratorCreatorApplicationController;
use App\Controllers\ModeratorController;
use App\Controllers\ModeratorListingController;
use App\Controllers\ModeratorReportController;
use App\Controllers\OrderController;
use App\Controllers\PasswordResetController;
use App\Controllers\ProfileController;
use App\Controllers\PublicCreatorController;
use App\Controllers\ReportController;
use App\Core\Router;
use App\Middleware\ActiveCreatorMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\RoleMiddleware;
use App\Middleware\VerifiedEmailMiddleware;

return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/legal', [LegalController::class, 'index']);
    $router->get('/terms', [LegalController::class, 'terms']);
    $router->get('/privacy', [LegalController::class, 'privacy']);
    $router->get('/cookies', [LegalController::class, 'cookies']);
    $router->get('/content-policy', [LegalController::class, 'contentPolicy']);
    $router->get('/creator-rules', [LegalController::class, 'creatorRules']);
    $router->get('/age-policy', [LegalController::class, 'agePolicy']);
    $router->get('/reporting-policy', [LegalController::class, 'reportingPolicy']);
    $router->get('/marketplace', [MarketplaceController::class, 'index']);
    $router->get('/marketplace/{slug}', [MarketplaceController::class, 'show']);
    $router->get('/media/{id}', [MediaController::class, 'show']);
    $router->get('/account', [AccountController::class, 'index'], [AuthMiddleware::class]);
    $router->get('/account/legal', [AccountController::class, 'legal'], [AuthMiddleware::class]);
    $router->get('/account/verify-email', [EmailVerificationController::class, 'status'], [AuthMiddleware::class]);
    $router->post('/account/verify-email/resend', [EmailVerificationController::class, 'resend'], [AuthMiddleware::class]);
    $router->get('/account/security/password', [AccountSecurityController::class, 'passwordForm'], [AuthMiddleware::class]);
    $router->post('/account/security/password', [AccountSecurityController::class, 'changePassword'], [AuthMiddleware::class]);
    $router->get('/account/notifications', [NotificationController::class, 'index'], [AuthMiddleware::class]);
    $router->post('/account/notifications/read-all', [NotificationController::class, 'markAllRead'], [AuthMiddleware::class]);
    $router->post('/account/notifications/{id}/read', [NotificationController::class, 'markRead'], [AuthMiddleware::class]);
    $router->get('/account/favorites', [FavoriteController::class, 'index'], [AuthMiddleware::class]);
    $router->get('/account/messages', [MessageController::class, 'index'], [AuthMiddleware::class]);
    $router->get('/account/messages/{id}', [MessageController::class, 'show'], [AuthMiddleware::class]);
    $router->post('/account/messages/{id}', [MessageController::class, 'send'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->post('/messages/start/{listingId}', [MessageController::class, 'start'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->get('/reports/listing/{id}', [ReportController::class, 'listingForm'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->post('/reports/listing/{id}', [ReportController::class, 'listingStore'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->get('/reports/user/{id}', [ReportController::class, 'userForm'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->post('/reports/user/{id}', [ReportController::class, 'userStore'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->get('/reports/message/{id}', [ReportController::class, 'messageForm'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->post('/reports/message/{id}', [ReportController::class, 'messageStore'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->post('/favorites/{listingId}', [FavoriteController::class, 'store'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->post('/favorites/{listingId}/delete', [FavoriteController::class, 'destroy'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->get('/account/orders', [OrderController::class, 'index'], [AuthMiddleware::class]);
    $router->get('/account/orders/{id}', [OrderController::class, 'show'], [AuthMiddleware::class]);
    $router->post('/account/orders/{id}/test-pay', [OrderController::class, 'testPay'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->get('/account/profile', [ProfileController::class, 'edit'], [AuthMiddleware::class]);
    $router->post('/account/profile', [ProfileController::class, 'update'], [AuthMiddleware::class]);
    $router->post('/account/profile/avatar', [ProfileController::class, 'uploadAvatar'], [AuthMiddleware::class]);
    $router->post('/account/profile/avatar/delete', [ProfileController::class, 'deleteAvatar'], [AuthMiddleware::class]);
    $router->get('/account/creator/apply', [CreatorApplicationController::class, 'showApply'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->post('/account/creator/apply', [CreatorApplicationController::class, 'apply'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->get('/account/creator/status', [CreatorApplicationController::class, 'status'], [AuthMiddleware::class]);
    $router->get('/checkout/{listingId}', [CheckoutController::class, 'show'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
    $router->post('/checkout/{listingId}', [CheckoutController::class, 'store'], [AuthMiddleware::class, VerifiedEmailMiddleware::class]);
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
        VerifiedEmailMiddleware::class,
    ]);
    $router->post('/creator/listings', [CreatorListingController::class, 'store'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
        VerifiedEmailMiddleware::class,
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
        VerifiedEmailMiddleware::class,
    ]);
    $router->post('/creator/listings/{id}', [CreatorListingController::class, 'update'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
        VerifiedEmailMiddleware::class,
    ]);
    $router->post('/creator/listings/{id}/submit', [CreatorListingController::class, 'submit'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
        VerifiedEmailMiddleware::class,
    ]);
    $router->get('/creator/listings/{id}/media', [CreatorMediaController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
        VerifiedEmailMiddleware::class,
    ]);
    $router->post('/creator/listings/{id}/media', [CreatorMediaController::class, 'store'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
        VerifiedEmailMiddleware::class,
    ]);
    $router->post('/creator/listings/{id}/media/{mediaId}/cover', [CreatorMediaController::class, 'setCover'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
        VerifiedEmailMiddleware::class,
    ]);
    $router->post('/creator/listings/{id}/media/{mediaId}/delete', [CreatorMediaController::class, 'destroy'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['creator']]],
        ActiveCreatorMiddleware::class,
        VerifiedEmailMiddleware::class,
    ]);
    $router->get('/creator/{username}', [PublicCreatorController::class, 'show']);
    $router->get('/moderator', [ModeratorController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->get('/moderator/reports', [ModeratorReportController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->get('/moderator/reports/{id}', [ModeratorReportController::class, 'show'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->post('/moderator/reports/{id}/review', [ModeratorReportController::class, 'review'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->post('/moderator/reports/{id}/resolve', [ModeratorReportController::class, 'resolve'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->post('/moderator/reports/{id}/dismiss', [ModeratorReportController::class, 'dismiss'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->post('/moderator/creators/{userId}/suspend', [ModeratorReportController::class, 'suspendCreator'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->post('/moderator/creators/{userId}/restore', [ModeratorReportController::class, 'restoreCreator'], [
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
    $router->post('/moderator/creator-applications/{id}/verify-age', [ModeratorCreatorApplicationController::class, 'verifyAge'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->post('/moderator/creator-applications/{id}/reject-age', [ModeratorCreatorApplicationController::class, 'rejectAge'], [
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
    $router->post('/moderator/listings/{id}/suspend', [ModeratorListingController::class, 'suspend'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->post('/moderator/listings/{id}/restore', [ModeratorListingController::class, 'restore'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['moderator']]],
    ]);
    $router->get('/admin', [AdminController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/admin/users', [AdminUserController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/admin/users/{id}', [AdminUserController::class, 'show'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->post('/admin/users/{id}/suspend', [AdminUserController::class, 'suspend'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->post('/admin/users/{id}/reactivate', [AdminUserController::class, 'reactivate'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/admin/creators', [AdminCreatorController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/admin/creators/{id}', [AdminCreatorController::class, 'show'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/admin/listings', [AdminListingController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/admin/listings/{id}', [AdminListingController::class, 'show'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/admin/orders', [AdminOrderController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/admin/orders/{id}', [AdminOrderController::class, 'show'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/admin/reports', [AdminReportController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/admin/reports/{id}', [AdminReportController::class, 'show'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/admin/audit', [AdminAuditController::class, 'index'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/admin/audit/{id}', [AdminAuditController::class, 'show'], [
        AuthMiddleware::class,
        [RoleMiddleware::class, [['admin']]],
    ]);
    $router->get('/register', [AuthController::class, 'showRegister'], [GuestMiddleware::class]);
    $router->post('/register', [AuthController::class, 'register'], [GuestMiddleware::class]);
    $router->get('/login', [AuthController::class, 'showLogin'], [GuestMiddleware::class]);
    $router->post('/login', [AuthController::class, 'login'], [GuestMiddleware::class]);
    $router->get('/forgot-password', [PasswordResetController::class, 'forgotForm'], [GuestMiddleware::class]);
    $router->post('/forgot-password', [PasswordResetController::class, 'requestReset'], [GuestMiddleware::class]);
    $router->get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'], [GuestMiddleware::class]);
    $router->post('/reset-password/{token}', [PasswordResetController::class, 'resetPassword'], [GuestMiddleware::class]);
    $router->get('/verify-email/{token}', [EmailVerificationController::class, 'verify']);
    $router->post('/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]);
};
