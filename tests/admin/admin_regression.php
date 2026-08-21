<?php

declare(strict_types=1);

use App\Services\AdminService;
use App\Services\MailService;

putenv('MAIL_MAILER=array');
putenv('MAIL_PASSWORD=');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$app = require $root . '/config/app.php';
$db = require $root . '/config/database.php';
$baseUrl = rtrim((string) $app['url'], '/');
$pass = 0;
$fail = 0;
$failures = [];
$suffix = 'a' . bin2hex(random_bytes(3));
$password = 'AdminSec1x';
$createdUserIds = [];
$createdListingIds = [];
$cookieFiles = [];

MailService::clear();

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
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => (bool) ($opts['follow'] ?? false),
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $opts['headers'] ?? [],
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
        return ['status' => 0, 'headers' => [], 'body' => ''];
    }
    return [
        'status' => $status,
        'headers' => preg_split("/\r\n/", trim(substr($raw, 0, $headerSize))) ?: [],
        'body' => substr($raw, $headerSize),
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
    $path = tempnam(sys_get_temp_dir(), 'eronyx_ad_');
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
    $post = http('POST', $baseUrl . '/login', [
        'cookie' => $cookie,
        'fields' => ['_csrf' => csrfFrom($page['body']), 'email' => $email, 'password' => $password],
    ]);
    return ['cookie' => $cookie, 'login' => $post];
}

function roleId(PDO $pdo, string $name): int
{
    $statement = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
    $statement->execute(['name' => $name]);
    return (int) $statement->fetchColumn();
}

function createUser(PDO $pdo, string $email, string $username, string $display, string $password, array $roles, bool $verified = true, string $status = 'active'): int
{
    global $createdUserIds;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $sql = $verified
        ? "INSERT INTO users (email, password_hash, status, email_verified_at) VALUES (:email, :password_hash, :status, CURRENT_TIMESTAMP)"
        : "INSERT INTO users (email, password_hash, status) VALUES (:email, :password_hash, :status)";
    $statement = $pdo->prepare($sql);
    $statement->execute(['email' => $email, 'password_hash' => $hash, 'status' => $status]);
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

function createListing(PDO $pdo, int $ownerId, string $title, string $slug, string $status = 'published', string $visibility = 'public', string $type = 'digital_content'): int
{
    global $createdListingIds;
    $statement = $pdo->prepare(
        "INSERT INTO listings (owner_user_id, title, slug, description, listing_type, status, price, currency, visibility, published_at)
         VALUES (:owner_user_id, :title, :slug, :description, :listing_type, :status, '5.00', 'EUR', :visibility, :published_at)"
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

function questions(PDO $pdo): int
{
    $row = $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch();
    return (int) ($row['Value'] ?? 0);
}

try {
    $adminId = createUser($pdo, "adm.{$suffix}@eronyx.test", "adm{$suffix}", 'Admin User', $password, ['buyer', 'admin']);
    $modId = createUser($pdo, "mod.{$suffix}@eronyx.test", "mod{$suffix}", 'Mod User', $password, ['buyer', 'moderator']);
    $buyerId = createUser($pdo, "buy.{$suffix}@eronyx.test", "buy{$suffix}", 'Buyer User', $password, ['buyer']);
    $buyerSearch = createUser($pdo, "findme.{$suffix}@eronyx.test", "findme{$suffix}", 'FindMe Name', $password, ['buyer']);
    $xssUser = createUser($pdo, "xss.{$suffix}@eronyx.test", "xss{$suffix}", '<script>alert(1)</script>', $password, ['buyer']);
    $suspendedId = createUser($pdo, "sus.{$suffix}@eronyx.test", "sus{$suffix}", 'Suspended User', $password, ['buyer'], true, 'suspended');
    $bannedId = createUser($pdo, "ban.{$suffix}@eronyx.test", "ban{$suffix}", 'Banned User', $password, ['buyer'], true, 'banned');
    $unverifiedId = createUser($pdo, "unv.{$suffix}@eronyx.test", "unv{$suffix}", 'Unverified User', $password, ['buyer'], false);
    $creatorId = createUser($pdo, "cr.{$suffix}@eronyx.test", "cr{$suffix}", 'Creator User', $password, ['buyer', 'creator']);
    $creatorSusId = createUser($pdo, "crs.{$suffix}@eronyx.test", "crs{$suffix}", 'Creator Sus', $password, ['buyer', 'creator']);
    $pdo->prepare("INSERT INTO creator_profiles (user_id, status) VALUES (:id, 'active')")->execute(['id' => $creatorId]);
    $pdo->prepare("INSERT INTO creator_profiles (user_id, status) VALUES (:id, 'suspended')")->execute(['id' => $creatorSusId]);
    $listingPub = createListing($pdo, $creatorId, 'Admin Pub ' . $suffix, "admin-pub-{$suffix}", 'published');
    $listingDraft = createListing($pdo, $creatorId, 'Admin Draft ' . $suffix, "admin-draft-{$suffix}", 'draft', 'private', 'service');
    $listingXss = createListing($pdo, $creatorId, '<img src=x onerror=alert(1)>', "admin-xss-{$suffix}", 'published');

    $pdo->prepare(
        "INSERT INTO orders (buyer_user_id, status, subtotal_amount, total_amount, currency)
         VALUES (:buyer, 'paid', '5.00', '5.00', 'EUR')"
    )->execute(['buyer' => $buyerId]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO order_items (order_id, listing_id, seller_user_id, title_snapshot, unit_price, quantity, total_amount, currency, status)
         VALUES (:order_id, :listing_id, :seller, 'Snap title', '5.00', 1, '5.00', 'EUR', 'pending')"
    )->execute(['order_id' => $orderId, 'listing_id' => $listingPub, 'seller' => $creatorId]);
    $pdo->prepare(
        "INSERT INTO payments (order_id, provider, external_id, amount, currency, status, paid_at)
         VALUES (:order_id, 'test', :ext, '5.00', 'EUR', 'paid', CURRENT_TIMESTAMP)"
    )->execute(['order_id' => $orderId, 'ext' => 'ext-' . $suffix]);

    $pdo->prepare(
        "INSERT INTO reports (reporter_user_id, target_type, target_id, reason_code, details, status)
         VALUES (:reporter, 'listing', :target, 'spam', 'report details', 'open')"
    )->execute(['reporter' => $buyerId, 'target' => $listingPub]);
    $reportId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO audit_logs (actor_user_id, event_type, entity_type, entity_id, metadata_json)
         VALUES (:actor, 'moderator_action', 'listing', :entity, :meta)"
    )->execute([
        'actor' => $modId,
        'entity' => $listingPub,
        'meta' => json_encode(['note' => '<script>xss</script>', 'ok' => true], JSON_UNESCAPED_UNICODE),
    ]);
    $auditId = (int) $pdo->lastInsertId();

    $guest = http('GET', $baseUrl . '/admin');
    check(1, 'Guest /admin 302 login', $guest['status'] === 302 && str_contains((string) headerValue($guest['headers'], 'Location'), '/login'));

    $buyerSession = login($baseUrl, "buy.{$suffix}@eronyx.test", $password);
    $buyerAdmin = http('GET', $baseUrl . '/admin', ['cookie' => $buyerSession['cookie']]);
    check(2, 'Buyer 403', $buyerAdmin['status'] === 403);

    $creatorSession = login($baseUrl, "cr.{$suffix}@eronyx.test", $password);
    $creatorAdmin = http('GET', $baseUrl . '/admin', ['cookie' => $creatorSession['cookie']]);
    check(3, 'Creator 403', $creatorAdmin['status'] === 403);

    $modSession = login($baseUrl, "mod.{$suffix}@eronyx.test", $password);
    $modAdmin = http('GET', $baseUrl . '/admin', ['cookie' => $modSession['cookie']]);
    check(4, 'Moderator sin admin 403', $modAdmin['status'] === 403);

    $adminSession = login($baseUrl, "adm.{$suffix}@eronyx.test", $password);
    $dash = http('GET', $baseUrl . '/admin', ['cookie' => $adminSession['cookie']]);
    check(5, 'Admin 200', $dash['status'] === 200 && str_contains($dash['body'], 'Administración'));

    $usersCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = \'active\'')->fetchColumn();
    check(6, 'Counts correctos', $dash['status'] === 200 && str_contains($dash['body'], (string) $usersCount) && str_contains($dash['body'], 'Usuarios'));
    check(7, 'No secrets dashboard', !str_contains($dash['body'], 'password_hash') && !str_contains($dash['body'], $password));
    check(8, 'Links admin correctos', str_contains($dash['body'], '/admin/users') && str_contains($dash['body'], '/admin/audit') && str_contains($dash['body'], '/admin/orders'));

    $users = http('GET', $baseUrl . '/admin/users', ['cookie' => $adminSession['cookie']]);
    check(9, 'Users list 200', $users['status'] === 200);
    $emailSearch = http('GET', $baseUrl . '/admin/users?q=' . rawurlencode("findme.{$suffix}@eronyx.test"), ['cookie' => $adminSession['cookie']]);
    check(10, 'Search email', $emailSearch['status'] === 200 && str_contains($emailSearch['body'], "findme.{$suffix}@eronyx.test"));
    $userSearch = http('GET', $baseUrl . '/admin/users?q=' . rawurlencode("findme{$suffix}"), ['cookie' => $adminSession['cookie']]);
    check(11, 'Search username', $userSearch['status'] === 200 && str_contains($userSearch['body'], "findme{$suffix}"));
    $statusFilter = http('GET', $baseUrl . '/admin/users?status=suspended', ['cookie' => $adminSession['cookie']]);
    check(12, 'status filter', $statusFilter['status'] === 200 && str_contains($statusFilter['body'], "sus{$suffix}") && !str_contains($statusFilter['body'], "findme{$suffix}"));
    $verifiedFilter = http('GET', $baseUrl . '/admin/users?email_verified=unverified', ['cookie' => $adminSession['cookie']]);
    check(13, 'verified filter', $verifiedFilter['status'] === 200 && str_contains($verifiedFilter['body'], "unv{$suffix}"));
    $roleFilter = http('GET', $baseUrl . '/admin/users?role=admin', ['cookie' => $adminSession['cookie']]);
    check(14, 'role filter', $roleFilter['status'] === 200 && str_contains($roleFilter['body'], "adm{$suffix}") && !str_contains($roleFilter['body'], "findme{$suffix}"));

    for ($i = 0; $i < 21; $i++) {
        createUser($pdo, "p{$i}.{$suffix}@eronyx.test", "p{$i}{$suffix}", "Page {$i}", $password, ['buyer']);
    }
    $page2 = http('GET', $baseUrl . '/admin/users?page=2', ['cookie' => $adminSession['cookie']]);
    check(15, 'pagination', $page2['status'] === 200 && str_contains($page2['body'], 'Página 2'));

    $detail = http('GET', $baseUrl . '/admin/users/' . $buyerId, ['cookie' => $adminSession['cookie']]);
    check(16, 'detail 200', $detail['status'] === 200 && str_contains($detail['body'], "buy.{$suffix}@eronyx.test"));
    $invalid = http('GET', $baseUrl . '/admin/users/99999999', ['cookie' => $adminSession['cookie']]);
    $badId = http('GET', $baseUrl . '/admin/users/abc', ['cookie' => $adminSession['cookie']]);
    check(17, 'ID invalid 404', $invalid['status'] === 404 && $badId['status'] === 404);
    check(18, 'No password hash', !str_contains($users['body'], 'password_hash') && !str_contains($detail['body'], 'password_hash') && !str_contains($detail['body'], '$2y$'));

    $badCsrf = http('POST', $baseUrl . '/admin/users/' . $buyerId . '/suspend', [
        'cookie' => $adminSession['cookie'],
        'fields' => ['_csrf' => 'invalid'],
    ]);
    check(19, 'CSRF invalid 403', $badCsrf['status'] === 403);

    $buyerPage = http('GET', $baseUrl . '/admin/users/' . $buyerId, ['cookie' => $adminSession['cookie']]);
    $suspend = http('POST', $baseUrl . '/admin/users/' . $buyerId . '/suspend', [
        'cookie' => $adminSession['cookie'],
        'fields' => ['_csrf' => csrfFrom($buyerPage['body'])],
    ]);
    $buyerStatus = (string) $pdo->query('SELECT status FROM users WHERE id = ' . (int) $buyerId)->fetchColumn();
    check(20, 'buyer suspend success', $suspend['status'] === 302 && $buyerStatus === 'suspended');

    $buyerAgain = http('GET', $baseUrl . '/account', ['cookie' => $buyerSession['cookie']]);
    check(21, 'Auth session buyer cae', $buyerAgain['status'] === 302);

    $auditBefore = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'user_suspended' AND entity_id = " . (int) $buyerId)->fetchColumn();
    $buyerPage2 = http('GET', $baseUrl . '/admin/users/' . $buyerId, ['cookie' => $adminSession['cookie']]);
    http('POST', $baseUrl . '/admin/users/' . $buyerId . '/suspend', [
        'cookie' => $adminSession['cookie'],
        'fields' => ['_csrf' => csrfFrom($buyerPage2['body'])],
    ]);
    $auditAfter = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'user_suspended' AND entity_id = " . (int) $buyerId)->fetchColumn();
    check(22, 'double suspend no duplicate audit', $auditBefore === 1 && $auditAfter === 1);

    $reactivate = http('POST', $baseUrl . '/admin/users/' . $buyerId . '/reactivate', [
        'cookie' => $adminSession['cookie'],
        'fields' => ['_csrf' => csrfFrom(http('GET', $baseUrl . '/admin/users/' . $buyerId, ['cookie' => $adminSession['cookie']])['body'])],
    ]);
    $buyerActive = (string) $pdo->query('SELECT status FROM users WHERE id = ' . (int) $buyerId)->fetchColumn();
    check(23, 'reactivate suspended', $reactivate['status'] === 302 && $buyerActive === 'active');

    $reactivateAgain = http('POST', $baseUrl . '/admin/users/' . $buyerId . '/reactivate', [
        'cookie' => $adminSession['cookie'],
        'fields' => ['_csrf' => csrfFrom(http('GET', $baseUrl . '/admin/users/' . $buyerId, ['cookie' => $adminSession['cookie']])['body'])],
    ]);
    check(24, 'reactivate active blocked', $reactivateAgain['status'] === 302 && (string) $pdo->query('SELECT status FROM users WHERE id = ' . (int) $buyerId)->fetchColumn() === 'active');

    $csrfOk = csrfFrom(http('GET', $baseUrl . '/admin/users/' . $buyerId, ['cookie' => $adminSession['cookie']])['body']);
    $self = http('POST', $baseUrl . '/admin/users/' . $adminId . '/suspend', [
        'cookie' => $adminSession['cookie'],
        'fields' => ['_csrf' => $csrfOk],
    ]);
    check(25, 'self suspend 403', $self['status'] === 403);

    $admin2 = createUser($pdo, "adm2.{$suffix}@eronyx.test", "adm2{$suffix}", 'Admin Two', $password, ['buyer', 'admin']);
    $admin2Target = http('POST', $baseUrl . '/admin/users/' . $admin2 . '/suspend', [
        'cookie' => $adminSession['cookie'],
        'fields' => ['_csrf' => $csrfOk],
    ]);
    check(26, 'admin target blocked', $admin2Target['status'] === 403);

    $modTarget = http('POST', $baseUrl . '/admin/users/' . $modId . '/suspend', [
        'cookie' => $adminSession['cookie'],
        'fields' => ['_csrf' => $csrfOk],
    ]);
    check(27, 'moderator target blocked', $modTarget['status'] === 403);

    $creators = http('GET', $baseUrl . '/admin/creators', ['cookie' => $adminSession['cookie']]);
    check(28, 'Creators list', $creators['status'] === 200 && str_contains($creators['body'], "cr{$suffix}"));
    $creatorFilter = http('GET', $baseUrl . '/admin/creators?status=suspended', ['cookie' => $adminSession['cookie']]);
    check(29, 'Filter creator status', $creatorFilter['status'] === 200 && str_contains($creatorFilter['body'], "crs{$suffix}") && !str_contains($creatorFilter['body'], "cr{$suffix}@"));
    $creatorDetail = http('GET', $baseUrl . '/admin/creators/' . $creatorId, ['cookie' => $adminSession['cookie']]);
    check(30, 'creator detail', $creatorDetail['status'] === 200 && str_contains($creatorDetail['body'], 'Verificación de edad'));
    check(31, 'no KYC docs', !str_contains($creatorDetail['body'], 'storage_key') && !str_contains($creatorDetail['body'], 'provider_reference') && !str_contains($creatorDetail['body'], 'passport'));
    $modAction = http('POST', $baseUrl . '/moderator/listings/' . $listingPub . '/approve', [
        'cookie' => $adminSession['cookie'],
        'fields' => ['_csrf' => csrfFrom($dash['body'])],
    ]);
    check(32, 'admin-only cannot moderator-action', $modAction['status'] === 403);

    $listings = http('GET', $baseUrl . '/admin/listings', ['cookie' => $adminSession['cookie']]);
    check(33, 'Listings list', $listings['status'] === 200 && str_contains($listings['body'], 'Admin Pub'));
    $listingQ = http('GET', $baseUrl . '/admin/listings?q=' . rawurlencode('Admin Pub ' . $suffix), ['cookie' => $adminSession['cookie']]);
    check(34, 'listings q', $listingQ['status'] === 200 && str_contains($listingQ['body'], 'Admin Pub'));
    $listingStatus = http('GET', $baseUrl . '/admin/listings?status=draft', ['cookie' => $adminSession['cookie']]);
    check(35, 'listings status', $listingStatus['status'] === 200 && str_contains($listingStatus['body'], 'Admin Draft'));
    $listingVis = http('GET', $baseUrl . '/admin/listings?visibility=private', ['cookie' => $adminSession['cookie']]);
    check(36, 'listings visibility', $listingVis['status'] === 200 && str_contains($listingVis['body'], 'Admin Draft'));
    $listingType = http('GET', $baseUrl . '/admin/listings?listing_type=service', ['cookie' => $adminSession['cookie']]);
    check(37, 'listings type', $listingType['status'] === 200 && str_contains($listingType['body'], 'Admin Draft'));
    $listingCreator = http('GET', $baseUrl . '/admin/listings?creator=' . $creatorId, ['cookie' => $adminSession['cookie']]);
    check(38, 'listings creator filter', $listingCreator['status'] === 200 && str_contains($listingCreator['body'], 'Admin Pub'));
    $listingDetail = http('GET', $baseUrl . '/admin/listings/' . $listingPub, ['cookie' => $adminSession['cookie']]);
    check(39, 'listing detail', $listingDetail['status'] === 200 && str_contains($listingDetail['body'], 'Admin Pub'));
    check(40, 'private storage_key not exposed', !str_contains($listingDetail['body'], 'storage_key') && !str_contains($listings['body'], 'storage_key'));

    $orders = http('GET', $baseUrl . '/admin/orders', ['cookie' => $adminSession['cookie']]);
    check(41, 'Orders list', $orders['status'] === 200 && str_contains($orders['body'], (string) $orderId));
    $orderStatus = http('GET', $baseUrl . '/admin/orders?status=paid', ['cookie' => $adminSession['cookie']]);
    check(42, 'orders status filter', $orderStatus['status'] === 200 && str_contains($orderStatus['body'], (string) $orderId));
    $orderBuyer = http('GET', $baseUrl . '/admin/orders?q=' . rawurlencode("buy.{$suffix}@eronyx.test"), ['cookie' => $adminSession['cookie']]);
    check(43, 'orders buyer search', $orderBuyer['status'] === 200 && str_contains($orderBuyer['body'], (string) $orderId));
    $today = date('Y-m-d');
    $orderDate = http('GET', $baseUrl . "/admin/orders?date_from={$today}&date_to={$today}", ['cookie' => $adminSession['cookie']]);
    check(44, 'orders date filter', $orderDate['status'] === 200 && str_contains($orderDate['body'], (string) $orderId));
    $orderDetail = http('GET', $baseUrl . '/admin/orders/' . $orderId, ['cookie' => $adminSession['cookie']]);
    check(45, 'order detail', $orderDetail['status'] === 200);
    check(46, 'items snapshot', str_contains($orderDetail['body'], 'Snap title'));
    check(47, 'payment metadata safe', str_contains($orderDetail['body'], 'test') && str_contains($orderDetail['body'], 'ext-' . $suffix) && !str_contains($orderDetail['body'], 'MAIL_PASSWORD') && !str_contains($orderDetail['body'], 'cvv'));
    check(48, 'no modify action', !str_contains(strtolower($orderDetail['body']), 'refund') && !str_contains($orderDetail['body'], '/test-pay') && !str_contains($orderDetail['body'], 'Marcar pagado') && !str_contains($orderDetail['body'], 'Reembols'));

    $reports = http('GET', $baseUrl . '/admin/reports', ['cookie' => $adminSession['cookie']]);
    check(49, 'Reports list', $reports['status'] === 200 && str_contains($reports['body'], (string) $reportId));
    $reportStatus = http('GET', $baseUrl . '/admin/reports?status=open', ['cookie' => $adminSession['cookie']]);
    check(50, 'reports status filter', $reportStatus['status'] === 200 && str_contains($reportStatus['body'], (string) $reportId));
    $reportDetail = http('GET', $baseUrl . '/admin/reports/' . $reportId, ['cookie' => $adminSession['cookie']]);
    check(51, 'report detail', $reportDetail['status'] === 200 && str_contains($reportDetail['body'], 'report details'));
    $resolve = http('POST', $baseUrl . '/moderator/reports/' . $reportId . '/resolve', [
        'cookie' => $adminSession['cookie'],
        'fields' => ['_csrf' => csrfFrom($dash['body'])],
    ]);
    check(52, 'admin-only cannot resolve', $resolve['status'] === 403);

    $auditList = http('GET', $baseUrl . '/admin/audit', ['cookie' => $adminSession['cookie']]);
    check(53, 'Audit list', $auditList['status'] === 200 && str_contains($auditList['body'], (string) $auditId));
    $auditEvent = http('GET', $baseUrl . '/admin/audit?event_type=moderator_action', ['cookie' => $adminSession['cookie']]);
    check(54, 'event filter', $auditEvent['status'] === 200 && str_contains($auditEvent['body'], (string) $auditId));
    $auditEntity = http('GET', $baseUrl . '/admin/audit?entity_type=listing', ['cookie' => $adminSession['cookie']]);
    check(55, 'entity filter', $auditEntity['status'] === 200 && str_contains($auditEntity['body'], (string) $auditId));
    $auditDate = http('GET', $baseUrl . "/admin/audit?date_from={$today}&date_to={$today}", ['cookie' => $adminSession['cookie']]);
    check(56, 'date filter', $auditDate['status'] === 200 && str_contains($auditDate['body'], (string) $auditId));
    $auditDetail = http('GET', $baseUrl . '/admin/audit/' . $auditId, ['cookie' => $adminSession['cookie']]);
    check(57, 'audit detail', $auditDetail['status'] === 200);
    check(58, 'metadata escaped', str_contains($auditDetail['body'], '&lt;script&gt;xss&lt;/script&gt;') && !str_contains($auditDetail['body'], '<script>xss</script>'));
    $auditEdit = http('POST', $baseUrl . '/admin/audit/' . $auditId, [
        'cookie' => $adminSession['cookie'],
        'fields' => ['_csrf' => csrfFrom($dash['body'])],
    ]);
    $auditDelete = http('GET', $baseUrl . '/admin/audit/' . $auditId . '/delete', ['cookie' => $adminSession['cookie']]);
    check(59, 'no edit/delete route', $auditEdit['status'] === 404 && $auditDelete['status'] === 404);

    $xssQ = http('GET', $baseUrl . '/admin/users?q=' . rawurlencode('<script>alert(1)</script>'), ['cookie' => $adminSession['cookie']]);
    check(60, 'q XSS escaped', $xssQ['status'] === 200 && str_contains($xssQ['body'], '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($xssQ['body'], '<script>alert(1)</script>'));
    check(61, 'audit metadata XSS escaped', str_contains($auditDetail['body'], '&lt;script&gt;xss&lt;/script&gt;'));
    $sqli = http('GET', $baseUrl . '/admin/users?q=' . rawurlencode("' OR 1=1 --"), ['cookie' => $adminSession['cookie']]);
    check(62, 'SQLi q no 500', $sqli['status'] === 200);
    $sortInject = http('GET', $baseUrl . '/admin/users?sort=' . rawurlencode('email; DROP TABLE users'), ['cookie' => $adminSession['cookie']]);
    check(63, 'sort injection ignored', $sortInject['status'] === 200);

    $svc = new AdminService($pdo);
    $qA = questions($pdo);
    $svc->users([]);
    $dA = questions($pdo) - $qA;
    for ($i = 0; $i < 10; $i++) {
        createUser($pdo, "n{$i}.{$suffix}@eronyx.test", "n{$i}{$suffix}", "N {$i}", $password, ['buyer']);
    }
    $qB = questions($pdo);
    $svc->users([]);
    $dB = questions($pdo) - $qB;
    check(64, 'Users list no per-row role queries', $dA > 0 && $dB > 0 && abs($dB - $dA) <= 4);
    echo "N+1 users questions delta small={$dA} vs {$dB}\n";

    $qL1 = questions($pdo);
    $svc->listings([]);
    $dL1 = questions($pdo) - $qL1;
    $qL2 = questions($pdo);
    $svc->listings([]);
    $dL2 = questions($pdo) - $qL2;
    check(65, 'Listings list no per-row creator queries', $dL1 > 0 && abs($dL2 - $dL1) <= 4);

    $qO1 = questions($pdo);
    $svc->orders([]);
    $dO1 = questions($pdo) - $qO1;
    $qO2 = questions($pdo);
    $svc->orders([]);
    $dO2 = questions($pdo) - $qO2;
    check(66, 'Orders list no per-row buyer query', $dO1 > 0 && abs($dO2 - $dO1) <= 4);

    foreach (
        [
            'users' => 'SELECT u.id FROM users u LEFT JOIN profiles p ON p.user_id = u.id LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id WHERE u.deleted_at IS NULL GROUP BY u.id LIMIT 20',
            'listings' => 'SELECT l.id, p.username FROM listings l LEFT JOIN profiles p ON p.user_id = l.owner_user_id WHERE l.deleted_at IS NULL LIMIT 20',
            'orders' => 'SELECT o.id, u.email FROM orders o INNER JOIN users u ON u.id = o.buyer_user_id WHERE o.deleted_at IS NULL LIMIT 20',
            'reports' => 'SELECT r.id FROM reports r LIMIT 20',
            'audit' => 'SELECT a.id FROM audit_logs a LIMIT 20',
        ] as $name => $sql
    ) {
        $explain = $pdo->query('EXPLAIN ' . $sql)->fetchAll();
        echo "EXPLAIN {$name}: " . json_encode($explain, JSON_UNESCAPED_UNICODE) . "\n";
    }

    $headers = implode("\n", $dash['headers']);
    check(67, 'SECURITY-1 headers', str_contains($headers, 'Content-Security-Policy') && str_contains($headers, 'no-store'));
} catch (Throwable $exception) {
    echo 'SUITE ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n";
    $fail++;
}

foreach ($cookieFiles as $file) {
    @unlink($file);
}
foreach (glob($root . '/storage/cache/rate-limits/*.json') ?: [] as $file) {
    @unlink($file);
}
MailService::clear();

try {
    $ids = implode(',', array_map('intval', $createdUserIds)) ?: '0';
    $listingIds = implode(',', array_map('intval', $createdListingIds)) ?: '0';
    $orderIds = $pdo->query("SELECT id FROM orders WHERE buyer_user_id IN ({$ids})")->fetchAll(PDO::FETCH_COLUMN);
    $orderList = $orderIds !== [] ? implode(',', array_map('intval', $orderIds)) : '0';
    $pdo->exec("DELETE FROM notifications WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM audit_logs WHERE actor_user_id IN ({$ids}) OR entity_id IN ({$ids}) OR entity_id IN ({$listingIds})");
    $pdo->exec("DELETE FROM moderation_actions WHERE moderator_user_id IN ({$ids}) OR target_id IN ({$ids}) OR target_id IN ({$listingIds})");
    $pdo->exec("DELETE FROM email_verification_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM password_reset_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM reports WHERE reporter_user_id IN ({$ids})");
    $pdo->exec("DELETE FROM favorites WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM payments WHERE order_id IN ({$orderList})");
    $pdo->exec("DELETE FROM order_items WHERE order_id IN ({$orderList})");
    $pdo->exec("DELETE FROM orders WHERE id IN ({$orderList})");
    $pdo->exec("DELETE FROM listing_categories WHERE listing_id IN ({$listingIds})");
    $pdo->exec("DELETE FROM listings WHERE id IN ({$listingIds}) OR owner_user_id IN ({$ids})");
    $pdo->exec("DELETE FROM age_verifications WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM creator_profiles WHERE user_id IN ({$ids})");
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
        'listings', 'listing_categories', 'orders', 'order_items', 'payments', 'favorites',
        'conversations', 'conversation_participants', 'messages', 'reports', 'moderation_actions',
        'audit_logs', 'notifications', 'password_reset_tokens', 'email_verification_tokens',
    ] as $table
) {
    $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
echo "\nDB counts:\n";
foreach ($counts as $table => $count) {
    echo "  {$table}={$count}\n";
}
echo 'Rate-limit files left: ' . count(glob($root . '/storage/cache/rate-limits/*.json') ?: []) . "\n";
echo 'Array mailer leftover: ' . count(MailService::sent()) . "\n";
echo "PASS={$pass} FAIL={$fail}\n";
if ($failures !== []) {
    echo "Failures:\n- " . implode("\n- ", $failures) . "\n";
}
exit($fail === 0 ? 0 : 1);
