<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Repositories\AuditLogRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\CreatorApplicationRepository;
use App\Repositories\FavoriteRepository;
use App\Repositories\ListingRepository;
use App\Repositories\MessageRepository;
use App\Repositories\ModerationActionRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\ReportRepository;
use App\Repositories\UserRepository;
use App\Services\CommerceService;
use App\Services\CreatorApplicationService;
use App\Services\FavoriteService;
use App\Services\ListingService;
use App\Services\MessagingService;
use App\Services\ModerationService;
use App\Services\NotificationService;
use App\Services\RateLimiter;
use App\Services\ReportService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$app = require $root . '/config/app.php';
$db = require $root . '/config/database.php';
$baseUrl = rtrim((string) $app['url'], '/');
$pass = 0;
$fail = 0;
$failures = [];
$suffix = 'n' . bin2hex(random_bytes(3));
$password = 'Notify1Passx';
$createdUserIds = [];
$createdListingIds = [];
$cookieFiles = [];
$explainNotes = [];

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['database'], $db['charset']),
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);

function check(int $num, string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    $label = sprintf('TEST %d %s', $num, $name);

    if ($ok) {
        $pass++;
        echo "PASS {$label}\n";
        return;
    }

    $fail++;
    $failures[] = $label . ($detail !== '' ? " — {$detail}" : '');
    echo "FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function headerValue(array $headers, string $name): ?string
{
    $name = strtolower($name);

    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) {
            return trim(substr($header, strlen($name) + 1));
        }
    }

    return null;
}

function http(string $method, string $url, array $opts = []): array
{
    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    $headers = $opts['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => (bool) ($opts['follow'] ?? false),
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if (!empty($opts['cookie'])) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $opts['cookie']);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $opts['cookie']);
    }

    if (isset($opts['fields'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['fields']);
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if (!is_string($raw)) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'raw_headers' => ''];
    }

    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $headerLines = preg_split("/\r\n/", trim($rawHeaders)) ?: [];

    return [
        'status' => $status,
        'headers' => $headerLines,
        'body' => $body,
        'raw_headers' => $rawHeaders,
    ];
}

function csrfFrom(string $html): string
{
    if (preg_match('/name="_csrf"\s+value="([a-f0-9]+)"/', $html, $matches) === 1) {
        return $matches[1];
    }

    return '';
}

function cookiePath(): string
{
    global $cookieFiles;
    $path = tempnam(sys_get_temp_dir(), 'eronyx_nk_');

    if ($path === false) {
        throw new RuntimeException('tempnam failed');
    }

    $cookieFiles[] = $path;

    return $path;
}

function login(string $baseUrl, string $email, string $password): array
{
    $cookie = cookiePath();
    $page = http('GET', $baseUrl . '/login', ['cookie' => $cookie]);
    $csrf = csrfFrom($page['body']);
    $post = http('POST', $baseUrl . '/login', [
        'cookie' => $cookie,
        'fields' => [
            '_csrf' => $csrf,
            'email' => $email,
            'password' => $password,
        ],
    ]);

    return ['cookie' => $cookie, 'login' => $post, 'csrf_page' => $page];
}

function roleId(PDO $pdo, string $name): int
{
    $statement = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
    $statement->execute(['name' => $name]);

    return (int) $statement->fetchColumn();
}

function createUser(PDO $pdo, string $email, string $username, string $display, string $password, array $roles): int
{
    global $createdUserIds;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $statement = $pdo->prepare(
        "INSERT INTO users (email, password_hash, status, email_verified_at) VALUES (:email, :password_hash, 'active', CURRENT_TIMESTAMP)"
    );
    $statement->execute(['email' => $email, 'password_hash' => $hash]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO profiles (user_id, display_name, username) VALUES (:user_id, :display_name, :username)')
        ->execute(['user_id' => $userId, 'display_name' => $display, 'username' => $username]);

    foreach ($roles as $role) {
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)')
            ->execute(['user_id' => $userId, 'role_id' => roleId($pdo, $role)]);
    }

    $createdUserIds[] = $userId;

    return $userId;
}

function createListing(
    PDO $pdo,
    int $ownerId,
    string $title,
    string $slug,
    string $visibility = 'public',
    string $status = 'published',
    string $type = 'digital_content'
): int {
    global $createdListingIds;
    $statement = $pdo->prepare(
        "INSERT INTO listings (
            owner_user_id, title, slug, description, listing_type, status, price, currency, visibility, published_at
         ) VALUES (
            :owner_user_id, :title, :slug, :description, :listing_type, :status, '5.00', 'EUR', :visibility, :published_at
         )"
    );
    $statement->execute([
        'owner_user_id' => $ownerId,
        'title' => $title,
        'slug' => $slug,
        'description' => 'Desc ' . $title,
        'listing_type' => $type,
        'status' => $status,
        'visibility' => $visibility,
        'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    $createdListingIds[] = $id;

    return $id;
}

function notificationService(PDO $pdo): NotificationService
{
    return new NotificationService(new NotificationRepository($pdo), new UserRepository($pdo));
}

function messagingService(PDO $pdo): MessagingService
{
    return new MessagingService(
        new ConversationRepository($pdo),
        new MessageRepository($pdo),
        new ListingRepository($pdo)
    );
}

function favoriteService(PDO $pdo): FavoriteService
{
    return new FavoriteService(new FavoriteRepository($pdo), new ListingRepository($pdo));
}

function moderationService(PDO $pdo): ModerationService
{
    return new ModerationService(
        new ReportRepository($pdo),
        new ModerationActionRepository($pdo),
        new AuditLogRepository($pdo),
        new ListingRepository($pdo),
        new UserRepository($pdo),
        new ProfileRepository($pdo),
        new MessageRepository($pdo),
        new CreatorApplicationRepository($pdo)
    );
}

function reportService(PDO $pdo): ReportService
{
    return new ReportService(
        new ReportRepository($pdo),
        new AuditLogRepository($pdo),
        new ListingRepository($pdo),
        new UserRepository($pdo),
        new ProfileRepository($pdo),
        new MessageRepository($pdo),
        new ConversationRepository($pdo)
    );
}

function listingService(PDO $pdo): ListingService
{
    return new ListingService(new Auth(new Session()), $pdo, new ListingRepository($pdo));
}

function countType(PDO $pdo, int $userId, string $type): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND type = :type'
    );
    $statement->execute(['user_id' => $userId, 'type' => $type]);

    return (int) $statement->fetchColumn();
}

function unreadCount(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL'
    );
    $statement->execute(['user_id' => $userId]);

    return (int) $statement->fetchColumn();
}

function latestNotification(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, user_id, type, title, body, action_url, read_at, dedupe_key
         FROM notifications
         WHERE user_id = :user_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function explainQuery(PDO $pdo, string $sql, array $params): array
{
    $statement = $pdo->prepare('EXPLAIN ' . $sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

try {
    $buyerAEmail = "buyer.a.{$suffix}@eronyx.test";
    $buyerBEmail = "buyer.b.{$suffix}@eronyx.test";
    $creatorEmail = "creator.{$suffix}@eronyx.test";
    $modEmail = "mod.{$suffix}@eronyx.test";

    $buyerA = createUser($pdo, $buyerAEmail, "buyera{$suffix}", 'Buyer A', $password, ['buyer']);
    $buyerB = createUser($pdo, $buyerBEmail, "buyerb{$suffix}", 'Buyer B', $password, ['buyer']);
    $creatorId = createUser($pdo, $creatorEmail, "creator{$suffix}", 'Creator N', $password, ['buyer', 'creator']);
    $pdo->prepare("INSERT INTO creator_profiles (user_id, status) VALUES (:id, 'active')")->execute(['id' => $creatorId]);
    $modId = createUser($pdo, $modEmail, "mod{$suffix}", 'Mod N', $password, ['moderator']);
    $deletedUser = createUser($pdo, "deleted.{$suffix}@eronyx.test", "deleted{$suffix}", 'Deleted', $password, ['buyer']);
    $suspendedUser = createUser($pdo, "susp.{$suffix}@eronyx.test", "susp{$suffix}", 'Suspended', $password, ['buyer']);

    $pdo->prepare("UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id")->execute(['id' => $deletedUser]);
    $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = :id")->execute(['id' => $suspendedUser]);

    $notifications = notificationService($pdo);
    $listingPublic = createListing($pdo, $creatorId, 'Notify Pub ' . $suffix, "notify-pub-{$suffix}");
    $listingPending = createListing($pdo, $creatorId, 'Notify Pend ' . $suffix, "notify-pend-{$suffix}", 'public', 'pending_review');
    $listingPendingReject = createListing($pdo, $creatorId, 'Notify Rej ' . $suffix, "notify-rej-{$suffix}", 'public', 'pending_review');
    $listingPhysical = createListing($pdo, $creatorId, 'Notify Phys ' . $suffix, "notify-phys-{$suffix}", 'public', 'published', 'physical_product');

    $guest = http('GET', $baseUrl . '/account/notifications');
    check(1, 'Guest notifications redirect', $guest['status'] === 302 && str_contains(headerValue($guest['headers'], 'Location') ?? '', '/login'));

    $sessionA = login($baseUrl, $buyerAEmail, $password);
    $emptyPage = http('GET', $baseUrl . '/account/notifications', ['cookie' => $sessionA['cookie']]);
    check(2, 'Auth empty notifications', $emptyPage['status'] === 200 && str_contains($emptyPage['body'], 'No tienes notificaciones todavía.'));

    $createdId = $notifications->notify(
        $buyerA,
        'listing_favorited',
        'Aviso de prueba',
        'Cuerpo de prueba',
        $buyerB,
        'listing',
        $listingPublic,
        '/marketplace/notify-pub-' . $suffix,
        'test:manual:' . $suffix
    );
    $pageA = http('GET', $baseUrl . '/account/notifications', ['cookie' => $sessionA['cookie']]);
    check(3, 'Buyer A sees own notification', $createdId !== null && $pageA['status'] === 200 && str_contains($pageA['body'], 'Aviso de prueba'));

    $sessionB = login($baseUrl, $buyerBEmail, $password);
    $pageB = http('GET', $baseUrl . '/account/notifications', ['cookie' => $sessionB['cookie']]);
    check(4, 'Buyer B does not see A notification', $pageB['status'] === 200 && !str_contains($pageB['body'], 'Aviso de prueba'));

    check(5, 'Unread count', unreadCount($pdo, $buyerA) === 1 && unreadCount($pdo, $buyerB) === 0);

    $csrfA = csrfFrom($pageA['body']);
    $read = http('POST', $baseUrl . '/account/notifications/' . $createdId . '/read', [
        'cookie' => $sessionA['cookie'],
        'fields' => ['_csrf' => $csrfA],
    ]);
    $row = $pdo->prepare('SELECT read_at FROM notifications WHERE id = :id');
    $row->execute(['id' => $createdId]);
    $readAt = $row->fetchColumn();
    check(6, 'Mark read with CSRF', $read['status'] === 302 && is_string($readAt) && $readAt !== '');

    $pageA2 = http('GET', $baseUrl . '/account/notifications', ['cookie' => $sessionA['cookie']]);
    $readAgain = http('POST', $baseUrl . '/account/notifications/' . $createdId . '/read', [
        'cookie' => $sessionA['cookie'],
        'fields' => ['_csrf' => csrfFrom($pageA2['body'])],
    ]);
    check(7, 'Mark read idempotent', in_array($readAgain['status'], [302, 200], true));

    $second = $notifications->notify(
        $buyerA,
        'report_updated',
        'Segundo aviso',
        'Cuerpo 2',
        null,
        'report',
        1,
        null,
        'test:second:' . $suffix
    );
    $beforeInvalid = $pdo->prepare('SELECT read_at FROM notifications WHERE id = :id');
    $beforeInvalid->execute(['id' => $second]);
    $beforeReadAt = $beforeInvalid->fetchColumn();
    $invalidCsrf = http('POST', $baseUrl . '/account/notifications/' . $second . '/read', [
        'cookie' => $sessionA['cookie'],
        'fields' => ['_csrf' => 'deadbeef'],
    ]);
    $afterInvalid = $pdo->prepare('SELECT read_at FROM notifications WHERE id = :id');
    $afterInvalid->execute(['id' => $second]);
    check(8, 'Invalid CSRF mark read', $invalidCsrf['status'] === 403 && $afterInvalid->fetchColumn() === $beforeReadAt);

    $idor = http('POST', $baseUrl . '/account/notifications/' . $second . '/read', [
        'cookie' => $sessionB['cookie'],
        'fields' => ['_csrf' => csrfFrom($pageB['body'])],
    ]);
    $idorRow = $pdo->prepare('SELECT read_at FROM notifications WHERE id = :id');
    $idorRow->execute(['id' => $second]);
    check(9, 'IDOR mark read blocked', $idor['status'] === 404 && $idorRow->fetchColumn() === null);

    $forB = $notifications->notify(
        $buyerB,
        'report_updated',
        'Solo B',
        null,
        null,
        'report',
        2,
        null,
        'test:onlyb:' . $suffix
    );
    $pageA3 = http('GET', $baseUrl . '/account/notifications', ['cookie' => $sessionA['cookie']]);
    $readAll = http('POST', $baseUrl . '/account/notifications/read-all', [
        'cookie' => $sessionA['cookie'],
        'fields' => ['_csrf' => csrfFrom($pageA3['body'])],
    ]);
    $aUnread = unreadCount($pdo, $buyerA);
    $bUnread = unreadCount($pdo, $buyerB);
    check(10, 'Read all only current user', $readAll['status'] === 302 && $aUnread === 0 && $bUnread === 1);

    $bBefore = $pdo->prepare('SELECT read_at FROM notifications WHERE id = :id');
    $bBefore->execute(['id' => $forB]);
    $bBeforeVal = $bBefore->fetchColumn();
    $readAllBad = http('POST', $baseUrl . '/account/notifications/read-all', [
        'cookie' => $sessionB['cookie'],
        'fields' => ['_csrf' => 'nope'],
    ]);
    $bAfter = $pdo->prepare('SELECT read_at FROM notifications WHERE id = :id');
    $bAfter->execute(['id' => $forB]);
    check(11, 'Read all invalid CSRF', $readAllBad['status'] === 403 && $bAfter->fetchColumn() === $bBeforeVal);

    $homeGuest = http('GET', $baseUrl . '/');
    check(12, 'Guest nav without notifications', $homeGuest['status'] === 200 && !str_contains($homeGuest['body'], 'Notificaciones'));

    $homeA = http('GET', $baseUrl . '/', ['cookie' => $sessionA['cookie']]);
    check(13, 'Auth nav notifications', $homeA['status'] === 200 && str_contains($homeA['body'], 'Notificaciones'));

    $xssId = $notifications->notify(
        $buyerA,
        'report_updated',
        '<script>alert(1)</script>',
        '<script>alert(2)</script>',
        null,
        'report',
        3,
        null,
        'test:xss:' . $suffix
    );
    $xssPage = http('GET', $baseUrl . '/account/notifications', ['cookie' => $sessionA['cookie']]);
    check(
        14,
        'XSS escaped',
        $xssId !== null
        && str_contains($xssPage['body'], '&lt;script&gt;alert(1)&lt;/script&gt;')
        && !str_contains($xssPage['body'], '<script>alert(1)</script>')
        && !str_contains($xssPage['body'], '<script>alert(2)</script>')
    );

    $beforeExternal = (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    $external = $notifications->notify(
        $buyerA,
        'report_updated',
        'Externa',
        'no',
        null,
        'report',
        4,
        'https://evil.example/phish',
        'test:ext:' . $suffix
    );
    $afterExternal = (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    check(15, 'External action_url rejected', $external === null && $afterExternal === $beforeExternal);

    $jsUrl = $notifications->notify(
        $buyerA,
        'report_updated',
        'JS',
        'no',
        null,
        'report',
        5,
        'javascript:alert(1)',
        'test:js:' . $suffix
    );
    check(16, 'javascript: action_url rejected', $jsUrl === null);

    $messaging = messagingService($pdo);
    $conversationId = $messaging->startConversation($buyerA, $listingPublic);
    $beforeCreatorMsg = countType($pdo, $creatorId, 'new_message');
    $beforeBuyerMsg = countType($pdo, $buyerA, 'new_message');
    $messaging->sendMessage($buyerA, $conversationId, 'Hola creator');
    check(17, 'new_message to recipient', countType($pdo, $creatorId, 'new_message') === $beforeCreatorMsg + 1);
    check(18, 'Sender not notified', countType($pdo, $buyerA, 'new_message') === $beforeBuyerMsg);

    $messageId = (int) $pdo->query('SELECT MAX(id) FROM messages')->fetchColumn();
    $notifications->notify(
        $creatorId,
        'new_message',
        'Tienes un mensaje nuevo',
        'Has recibido un mensaje en una de tus conversaciones.',
        $buyerA,
        'message',
        $messageId,
        '/account/messages/' . $conversationId,
        'message:' . $messageId . ':recipient:' . $creatorId
    );
    check(19, 'Message retry does not duplicate', countType($pdo, $creatorId, 'new_message') === 1);

    $favorites = favoriteService($pdo);
    $beforeFav = countType($pdo, $creatorId, 'listing_favorited');
    $favorites->addFavorite($buyerA, $listingPublic);
    $favorites->addFavorite($buyerA, $listingPublic);
    check(20, 'Favorite notifies owner once', countType($pdo, $creatorId, 'listing_favorited') === $beforeFav + 1);

    $selfFavBlocked = false;
    try {
        $favorites->addFavorite($creatorId, $listingPublic);
    } catch (RuntimeException $exception) {
        $selfFavBlocked = $exception->getMessage() === 'forbidden';
    }
    check(21, 'Self favorite no notification', $selfFavBlocked && countType($pdo, $creatorId, 'listing_favorited') === $beforeFav + 1);

    $apps = new CreatorApplicationService($pdo);
    $applicant = createUser($pdo, "apply.{$suffix}@eronyx.test", "apply{$suffix}", 'Applicant', $password, ['buyer']);
    $apps->apply($applicant);
    $application = $apps->findForUser($applicant);
    $approved = $apps->approve((int) $application['id'], $modId);
    $doubleApprove = $apps->approve((int) $application['id'], $modId);
    check(22, 'Creator approve notification', $approved && countType($pdo, $applicant, 'creator_application_approved') === 1);

    $rejectUser = createUser($pdo, "rej.{$suffix}@eronyx.test", "reju{$suffix}", 'Rejectee', $password, ['buyer']);
    $apps->apply($rejectUser);
    $rejectApp = $apps->findForUser($rejectUser);
    $rejected = $apps->reject((int) $rejectApp['id'], $modId);
    $doubleReject = $apps->reject((int) $rejectApp['id'], $modId);
    check(23, 'Creator reject notification', $rejected && countType($pdo, $rejectUser, 'creator_application_rejected') === 1);
    check(24, 'Double moderation no duplicate', $doubleApprove === false && $doubleReject === false
        && countType($pdo, $applicant, 'creator_application_approved') === 1
        && countType($pdo, $rejectUser, 'creator_application_rejected') === 1);

    $listings = listingService($pdo);
    $listings->approve($listingPending);
    $listings->approve($listingPending);
    check(25, 'Listing approve notifies owner', countType($pdo, $creatorId, 'listing_approved') === 1);
    $listings->reject($listingPendingReject);
    $listings->reject($listingPendingReject);
    check(26, 'Listing reject notifies owner', countType($pdo, $creatorId, 'listing_rejected') === 1);

    $moderation = moderationService($pdo);
    $suspend = $moderation->suspendListing($modId, $listingPublic);
    $suspendAgain = $moderation->suspendListing($modId, $listingPublic);
    check(27, 'Listing suspend notifies owner', $suspend === 'updated' && $suspendAgain === 'noop' && countType($pdo, $creatorId, 'listing_suspended') === 1);
    $restore = $moderation->restoreListing($modId, $listingPublic);
    $restoreAgain = $moderation->restoreListing($modId, $listingPublic);
    check(28, 'Listing restore notifies owner', $restore === 'updated' && $restoreAgain === 'noop' && countType($pdo, $creatorId, 'listing_restored') === 1);

    $creatorSuspend = $moderation->suspendCreator($modId, $applicant);
    $creatorSuspendAgain = $moderation->suspendCreator($modId, $applicant);
    check(29, 'Creator suspend notifies creator', $creatorSuspend === 'updated' && $creatorSuspendAgain === 'noop' && countType($pdo, $applicant, 'creator_suspended') === 1);
    $creatorRestore = $moderation->restoreCreator($modId, $applicant);
    $creatorRestoreAgain = $moderation->restoreCreator($modId, $applicant);
    check(30, 'Creator restore notifies creator', $creatorRestore === 'updated' && $creatorRestoreAgain === 'noop' && countType($pdo, $applicant, 'creator_restored') === 1);

    $reports = reportService($pdo);
    $reportResolved = $reports->reportListing($buyerA, $listingPublic, 'spam', 'nota interna secreta');
    $reportDismissed = $reports->reportUser($buyerB, $creatorId, 'harassment', 'otra nota interna');
    $resolve = $moderation->resolve($modId, $reportResolved);
    $resolveAgain = $moderation->resolve($modId, $reportResolved);
    $beforeDismiss = countType($pdo, $buyerB, 'report_updated');
    $dismiss = $moderation->dismiss($modId, $reportDismissed);
    $dismissAgain = $moderation->dismiss($modId, $reportDismissed);
    $resolvedRow = latestNotification($pdo, $buyerA);
    check(31, 'Report resolved notifies reporter', $resolve === 'updated' && $resolveAgain === 'noop' && countType($pdo, $buyerA, 'report_updated') >= 1);
    check(32, 'Report dismissed notifies reporter', $dismiss === 'updated' && $dismissAgain === 'noop' && countType($pdo, $buyerB, 'report_updated') === $beforeDismiss + 1);
    $reportPage = http('GET', $baseUrl . '/account/notifications', ['cookie' => $sessionA['cookie']]);
    check(
        33,
        'No internal moderation notes leaked',
        is_array($resolvedRow)
        && $resolvedRow['body'] === 'Tu reporte ha sido revisado.'
        && !str_contains((string) $resolvedRow['body'], 'nota interna')
        && !str_contains($reportPage['body'], 'nota interna secreta')
        && ($resolvedRow['action_url'] === null || $resolvedRow['action_url'] === '')
    );

    $commerce = new CommerceService($pdo);
    $checkout = $commerce->createCheckout($buyerA, $listingPublic);
    $paidDigital = $commerce->confirmTestPayment($checkout['order_id'], $buyerA);
    $paidDigitalAgain = $commerce->confirmTestPayment($checkout['order_id'], $buyerA);
    $physicalCheckout = $commerce->createCheckout($buyerB, $listingPhysical);
    $paidPhysical = $commerce->confirmTestPayment($physicalCheckout['order_id'], $buyerB);
    check(
        34,
        'Commerce notifies buyer by state',
        $paidDigital
        && $paidPhysical
        && countType($pdo, $buyerA, 'order_completed') === 1
        && countType($pdo, $buyerA, 'order_paid') === 0
        && countType($pdo, $buyerB, 'order_paid') === 1
        && countType($pdo, $buyerB, 'order_completed') === 0
    );
    check(35, 'Double commerce event no duplicate', $paidDigitalAgain === false && countType($pdo, $buyerA, 'order_completed') === 1);

    $deletedNotify = $notifications->notify(
        $deletedUser,
        'report_updated',
        'Deleted',
        'no',
        null,
        'report',
        9,
        null,
        'test:deleted:' . $suffix
    );
    check(36, 'Deleted user not notified', $deletedNotify === null && countType($pdo, $deletedUser, 'report_updated') === 0);

    $suspendedNotify = $notifications->notify(
        $suspendedUser,
        'report_updated',
        'Suspended inbox',
        'queda guardada',
        null,
        'report',
        10,
        null,
        'test:suspended:' . $suffix
    );
    $sessionSuspended = login($baseUrl, "susp.{$suffix}@eronyx.test", $password);
    $suspendedPage = http('GET', $baseUrl . '/account/notifications', ['cookie' => $sessionSuspended['cookie']]);
    check(
        37,
        'Suspended account cannot list notifications',
        $suspendedNotify !== null
        && $sessionSuspended['login']['status'] === 200
        && $suspendedPage['status'] === 302
        && str_contains(headerValue($suspendedPage['headers'], 'Location') ?? '', '/login')
    );

    $freshA = http('GET', $baseUrl . '/account/notifications', ['cookie' => $sessionA['cookie']]);
    $freshCsrf = csrfFrom($freshA['body']);
    $invalidId = http('POST', $baseUrl . '/account/notifications/abc/read', [
        'cookie' => $sessionA['cookie'],
        'fields' => ['_csrf' => $freshCsrf],
    ]);
    $missingGet = http('GET', $baseUrl . '/account/notifications/abc');
    check(38, 'Invalid id 404', $invalidId['status'] === 404 && $missingGet['status'] === 404);

    $freshA2 = http('GET', $baseUrl . '/account/notifications', ['cookie' => $sessionA['cookie']]);
    $missing = http('POST', $baseUrl . '/account/notifications/99999999/read', [
        'cookie' => $sessionA['cookie'],
        'fields' => ['_csrf' => csrfFrom($freshA2['body'])],
    ]);
    check(39, 'Missing notification 404', $missing['status'] === 404);

    $sqliGet = http('GET', $baseUrl . '/account/notifications?page=1%27%20OR%201=1', ['cookie' => $sessionA['cookie']]);
    $sqliPost = http('POST', $baseUrl . '/account/notifications/1%20OR%201=1/read', [
        'cookie' => $sessionA['cookie'],
        'fields' => ['_csrf' => 'x'],
    ]);
    check(40, 'SQLi params safe', $sqliGet['status'] === 200 && in_array($sqliPost['status'], [403, 404], true));

    $home = http('GET', $baseUrl . '/');
    $market = http('GET', $baseUrl . '/marketplace');
    $loginPage = http('GET', $baseUrl . '/login');
    $registerPage = http('GET', $baseUrl . '/register');
    $account = http('GET', $baseUrl . '/account', ['cookie' => $sessionA['cookie']]);
    $profile = http('GET', $baseUrl . '/account/profile', ['cookie' => $sessionA['cookie']]);
    $favs = http('GET', $baseUrl . '/account/favorites', ['cookie' => $sessionA['cookie']]);
    $msgs = http('GET', $baseUrl . '/account/messages', ['cookie' => $sessionA['cookie']]);
    $orders = http('GET', $baseUrl . '/account/orders', ['cookie' => $sessionA['cookie']]);
    $sessionCreator = login($baseUrl, $creatorEmail, $password);
    $creatorHome = http('GET', $baseUrl . '/creator', ['cookie' => $sessionCreator['cookie']]);
    $creatorListings = http('GET', $baseUrl . '/creator/listings', ['cookie' => $sessionCreator['cookie']]);
    $sessionMod = login($baseUrl, $modEmail, $password);
    $modHome = http('GET', $baseUrl . '/moderator', ['cookie' => $sessionMod['cookie']]);
    $modReports = http('GET', $baseUrl . '/moderator/reports', ['cookie' => $sessionMod['cookie']]);
    $modListings = http('GET', $baseUrl . '/moderator/listings', ['cookie' => $sessionMod['cookie']]);
    $publicListing = http('GET', $baseUrl . '/marketplace/notify-pub-' . $suffix);
    $regressOk = $home['status'] === 200
        && $market['status'] === 200
        && $loginPage['status'] === 200
        && $registerPage['status'] === 200
        && $account['status'] === 200
        && $profile['status'] === 200
        && $favs['status'] === 200
        && $msgs['status'] === 200
        && $orders['status'] === 200
        && $creatorHome['status'] === 200
        && $creatorListings['status'] === 200
        && $modHome['status'] === 200
        && $modReports['status'] === 200
        && $modListings['status'] === 200
        && $publicListing['status'] === 200;
    if ($regressOk) {
        $pass++;
        echo "PASS REGRESSION core pages\n";
    } else {
        $fail++;
        $failures[] = 'REGRESSION core pages'
            . " home={$home['status']} market={$market['status']} login={$loginPage['status']} register={$registerPage['status']}"
            . " account={$account['status']} profile={$profile['status']} favs={$favs['status']} msgs={$msgs['status']} orders={$orders['status']}"
            . " creator={$creatorHome['status']} listings={$creatorListings['status']} mod={$modHome['status']} reports={$modReports['status']}"
            . " modListings={$modListings['status']} public={$publicListing['status']}";
        echo "FAIL REGRESSION core pages\n";
    }

    $securityHeaders = hasHeader($home['headers'], 'X-Frame-Options', 'DENY')
        && hasHeader($home['headers'], 'X-Content-Type-Options', 'nosniff')
        && headerValue($home['headers'], 'Content-Security-Policy') !== null
        && headerValue($account['headers'], 'Cache-Control') !== null
        && str_contains((string) headerValue($account['headers'], 'Cache-Control'), 'no-store');
    $limiterExists = class_exists(RateLimiter::class);
    if ($securityHeaders && $limiterExists) {
        $pass++;
        echo "PASS SECURITY-1 headers and rate limiter still present\n";
    } else {
        $fail++;
        $failures[] = 'SECURITY-1 regression';
        echo "FAIL SECURITY-1 regression\n";
    }

    $explainList = explainQuery(
        $pdo,
        'SELECT id FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET 0',
        ['user_id' => $buyerA]
    );
    $explainCount = explainQuery(
        $pdo,
        'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL',
        ['user_id' => $buyerA]
    );
    $explainNotes['list'] = $explainList;
    $explainNotes['unread'] = $explainCount;
    echo "EXPLAIN list: " . json_encode($explainList, JSON_UNESCAPED_UNICODE) . "\n";
    echo "EXPLAIN unread: " . json_encode($explainCount, JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $exception) {
    echo 'SUITE ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n";
    $fail++;
}

function hasHeader(array $headers, string $name, string $expected): bool
{
    $value = headerValue($headers, $name);

    return is_string($value) && strcasecmp($value, $expected) === 0;
}

foreach ($cookieFiles as $file) {
    @unlink($file);
}

foreach (glob($root . '/storage/cache/rate-limits/*.json') ?: [] as $file) {
    @unlink($file);
}

try {
    $ids = implode(',', array_map('intval', $createdUserIds)) ?: '0';
    $listingIds = implode(',', array_map('intval', $createdListingIds)) ?: '0';
    $orderIds = $pdo->query("SELECT id FROM orders WHERE buyer_user_id IN ({$ids})")->fetchAll(PDO::FETCH_COLUMN);
    $orderList = $orderIds !== [] ? implode(',', array_map('intval', $orderIds)) : '0';
    $conversationIds = $pdo->query(
        "SELECT conversation_id FROM conversation_participants WHERE user_id IN ({$ids})"
    )->fetchAll(PDO::FETCH_COLUMN);
    $conversationList = $conversationIds !== [] ? implode(',', array_map('intval', $conversationIds)) : '0';
    $reportIds = $pdo->query("SELECT id FROM reports WHERE reporter_user_id IN ({$ids})")->fetchAll(PDO::FETCH_COLUMN);
    $reportList = $reportIds !== [] ? implode(',', array_map('intval', $reportIds)) : '0';

    $pdo->exec("DELETE FROM notifications WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM audit_logs WHERE actor_user_id IN ({$ids}) OR entity_id IN ({$listingIds}) OR entity_id IN ({$reportList})");
    $pdo->exec("DELETE FROM moderation_actions WHERE moderator_user_id IN ({$ids}) OR target_id IN ({$listingIds}) OR target_id IN ({$ids}) OR target_id IN ({$reportList})");
    $pdo->exec("DELETE FROM reports WHERE reporter_user_id IN ({$ids})");
    $pdo->exec("DELETE FROM messages WHERE conversation_id IN ({$conversationList}) OR sender_user_id IN ({$ids})");
    $pdo->exec("DELETE FROM conversation_participants WHERE user_id IN ({$ids}) OR conversation_id IN ({$conversationList})");
    $pdo->exec("DELETE FROM conversations WHERE id IN ({$conversationList}) OR created_by_user_id IN ({$ids})");
    $pdo->exec("DELETE FROM favorites WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM private_content_access WHERE user_id IN ({$ids}) OR listing_id IN ({$listingIds})");
    $pdo->exec("DELETE FROM payments WHERE order_id IN ({$orderList})");
    $pdo->exec("DELETE FROM order_items WHERE order_id IN ({$orderList})");
    $pdo->exec("DELETE FROM orders WHERE id IN ({$orderList})");
    $pdo->exec("DELETE FROM listing_media WHERE listing_id IN ({$listingIds})");
    $pdo->exec("DELETE FROM media_files WHERE owner_user_id IN ({$ids})");
    $pdo->exec("DELETE FROM listing_categories WHERE listing_id IN ({$listingIds})");
    $pdo->exec("DELETE FROM listings WHERE id IN ({$listingIds}) OR owner_user_id IN ({$ids})");
    $pdo->exec("DELETE FROM age_verifications WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM creator_profiles WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM email_verification_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM user_roles WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM profiles WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM users WHERE id IN ({$ids})");
} catch (Throwable $cleanupError) {
    echo 'CLEANUP ERROR: ' . $cleanupError->getMessage() . "\n";
    $fail++;
}

$counts = [];
foreach (
    [
        'users', 'profiles', 'user_roles', 'creator_profiles', 'age_verifications', 'roles', 'categories',
        'listings', 'listing_categories', 'media_files', 'listing_media', 'private_content_access',
        'orders', 'order_items', 'payments', 'favorites', 'conversations', 'conversation_participants',
        'messages', 'reports', 'moderation_actions', 'audit_logs', 'notifications',
    ] as $table
) {
    $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

echo "\nDB counts:\n";
foreach ($counts as $table => $count) {
    echo "  {$table}={$count}\n";
}

$rateLeft = glob($root . '/storage/cache/rate-limits/*.json') ?: [];
$mediaLeft = [];
$mediaRoot = $root . '/storage/private/media';
if (is_dir($mediaRoot)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mediaRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
            $mediaLeft[] = $file->getPathname();
        }
    }
}

echo 'Rate-limit files left: ' . count($rateLeft) . "\n";
echo 'Media temp files left: ' . count($mediaLeft) . "\n";
echo "PASS={$pass} FAIL={$fail}\n";

if ($failures !== []) {
    echo "Failures:\n- " . implode("\n- ", $failures) . "\n";
}

exit($fail === 0 ? 0 : 1);
