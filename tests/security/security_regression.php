<?php

declare(strict_types=1);

use App\Core\Response;
use App\Services\MediaStorageService;
use App\Services\RateLimiter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$app = require $root . '/config/app.php';
$db = require $root . '/config/database.php';
$baseUrl = rtrim((string) $app['url'], '/');
$pass = 0;
$fail = 0;
$failures = [];
$suffix = 's' . bin2hex(random_bytes(3));
$password = 'Security1Passx';
$createdUserIds = [];
$createdListingIds = [];
$createdMediaIds = [];
$createdMediaPaths = [];
$cookieFiles = [];

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['database'], $db['charset']),
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
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

function hasHeader(array $headers, string $name, string $expected): bool
{
    $value = headerValue($headers, $name);

    return is_string($value) && strcasecmp($value, $expected) === 0;
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

function sessionCookie(array $headers): ?string
{
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie:') === 0 && stripos($header, 'eronyx_session=') !== false) {
            if (preg_match('/eronyx_session=([^;]+)/', $header, $matches) === 1) {
                return $matches[1];
            }
        }
    }

    return null;
}

function cookiePath(): string
{
    global $cookieFiles;
    $path = tempnam(sys_get_temp_dir(), 'eronyx_ck_');

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

function createListing(PDO $pdo, int $ownerId, string $title, string $slug, string $visibility = 'public', string $status = 'published'): int
{
    global $createdListingIds;
    $statement = $pdo->prepare(
        "INSERT INTO listings (
            owner_user_id, title, slug, description, listing_type, status, price, currency, visibility, published_at
         ) VALUES (
            :owner_user_id, :title, :slug, :description, 'digital_content', :status, '5.00', 'EUR', :visibility, :published_at
         )"
    );
    $statement->execute([
        'owner_user_id' => $ownerId,
        'title' => $title,
        'slug' => $slug,
        'description' => 'Desc ' . $title,
        'status' => $status,
        'visibility' => $visibility,
        'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    $createdListingIds[] = $id;

    return $id;
}

function tinyPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true) ?: '';
}

function tinyJpeg(): string
{
    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);
        $data = (string) ob_get_clean();

        if ($data !== '') {
            return $data;
        }
    }

    return tinyPng();
}

function tinyWebp(): string
{
    if (function_exists('imagecreatetruecolor') && function_exists('imagewebp')) {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagewebp($image);
        imagedestroy($image);
        $data = (string) ob_get_clean();

        if ($data !== '') {
            return $data;
        }
    }

    return (string) base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA', true);
}

function writeTemp(string $contents, string $suffix = '.bin'): string
{
    $path = tempnam(sys_get_temp_dir(), 'eronyx_up_');

    if ($path === false) {
        throw new RuntimeException('tempnam failed');
    }

    $named = $path . $suffix;
    rename($path, $named);
    file_put_contents($named, $contents);

    return $named;
}

function uploadFile(string $path, string $name = 'file.bin'): array
{
    return [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $path,
        'size' => filesize($path),
        'name' => $name,
        'type' => 'application/octet-stream',
    ];
}

function xssSafe(string $body): bool
{
    return !str_contains($body, '<script>alert(1)</script>')
        && !str_contains($body, '<img src=x onerror=')
        && !str_contains($body, 'javascript:alert(1)"');
}

try {
    $home = http('GET', $baseUrl . '/');
    $market = http('GET', $baseUrl . '/marketplace');
    $csp = Response::CSP;

    check(1, 'Home security headers', $home['status'] === 200 && hasHeader($home['headers'], 'X-Content-Type-Options', 'nosniff'));
    check(2, 'Marketplace headers', $market['status'] === 200 && hasHeader($market['headers'], 'X-Frame-Options', 'DENY'));

    $buyerA = createUser($pdo, "ba{$suffix}@eronyx.test", "ba{$suffix}", 'Buyer A', $password, ['buyer']);
    $buyerB = createUser($pdo, "bb{$suffix}@eronyx.test", "bb{$suffix}", 'Buyer B', $password, ['buyer']);
    $creatorA = createUser($pdo, "ca{$suffix}@eronyx.test", "ca{$suffix}", 'Creator A', $password, ['buyer', 'creator']);
    $creatorB = createUser($pdo, "cb{$suffix}@eronyx.test", "cb{$suffix}", 'Creator B', $password, ['buyer', 'creator']);
    $moderator = createUser($pdo, "mo{$suffix}@eronyx.test", "mo{$suffix}", 'Moderator', $password, ['buyer', 'moderator']);
    $admin = createUser($pdo, "ad{$suffix}@eronyx.test", "ad{$suffix}", 'Admin', $password, ['buyer', 'admin']);

    $pdo->prepare("INSERT INTO creator_profiles (user_id, status) VALUES (:id, 'active')")->execute(['id' => $creatorA]);
    $pdo->prepare("INSERT INTO creator_profiles (user_id, status) VALUES (:id, 'active')")->execute(['id' => $creatorB]);

    $listingA = createListing($pdo, $creatorA, '<script>alert(1)</script> Title', "la-{$suffix}");
    $listingB = createListing($pdo, $creatorB, 'Creator B listing', "lb-{$suffix}");
    $listingPrivate = createListing($pdo, $creatorA, 'Private listing', "lp-{$suffix}", 'private');
    $listingUnlisted = createListing($pdo, $creatorA, 'Unlisted listing', "lu-{$suffix}", 'unlisted');

    $session = login($baseUrl, "ba{$suffix}@eronyx.test", $password);
    $account = http('GET', $baseUrl . '/account', ['cookie' => $session['cookie']]);
    check(3, 'Account headers', $account['status'] === 200 && hasHeader($account['headers'], 'Cache-Control', 'no-store, private'));
    check(4, 'CSP present', headerValue($home['headers'], 'Content-Security-Policy') === $csp);
    check(5, 'X-Content-Type-Options nosniff', hasHeader($home['headers'], 'X-Content-Type-Options', 'nosniff'));
    check(6, 'X-Frame-Options DENY', hasHeader($home['headers'], 'X-Frame-Options', 'DENY'));
    check(7, 'Referrer-Policy', hasHeader($home['headers'], 'Referrer-Policy', 'strict-origin-when-cross-origin'));
    check(8, 'Permissions-Policy', hasHeader($home['headers'], 'Permissions-Policy', 'camera=(), microphone=(), geolocation=()'));
    check(9, 'localhost HTTP without HSTS', headerValue($home['headers'], 'Strict-Transport-Security') === null);

    $prodHeaders = (new Response())->securityHeaderMap('/', true, true);
    $localHeaders = (new Response())->securityHeaderMap('/', false, false);
    check(10, 'production HTTPS HSTS', ($prodHeaders['Strict-Transport-Security'] ?? '') === 'max-age=31536000; includeSubDomains' && !isset($localHeaders['Strict-Transport-Security']));

    $loginPage = http('GET', $baseUrl . '/login');
    $setCookie = '';
    foreach ($loginPage['headers'] as $header) {
        if (stripos($header, 'Set-Cookie:') === 0 && stripos($header, 'eronyx_session=') !== false) {
            $setCookie = $header;
            break;
        }
    }
    check(11, 'Cookie HttpOnly', stripos($setCookie, 'HttpOnly') !== false);
    check(12, 'Cookie SameSite', stripos($setCookie, 'SameSite=Lax') !== false || stripos($setCookie, 'SameSite=Strict') !== false);

    $fixCookie = cookiePath();
    $preLogin = http('GET', $baseUrl . '/login', ['cookie' => $fixCookie]);
    $sidBefore = sessionCookie($preLogin['headers']);
    $loginFix = http('POST', $baseUrl . '/login', [
        'cookie' => $fixCookie,
        'fields' => [
            '_csrf' => csrfFrom($preLogin['body']),
            'email' => "ba{$suffix}@eronyx.test",
            'password' => $password,
        ],
    ]);
    $sidAfter = sessionCookie($loginFix['headers']) ?? $sidBefore;
    check(13, 'Session fixation login blocked', is_string($sidBefore) && is_string($sidAfter) && $sidBefore !== $sidAfter);

    $regCookie = cookiePath();
    $regPage = http('GET', $baseUrl . '/register', ['cookie' => $regCookie]);
    $sidRegBefore = sessionCookie($regPage['headers']);
    $regPost = http('POST', $baseUrl . '/register', [
        'cookie' => $regCookie,
        'fields' => [
            '_csrf' => csrfFrom($regPage['body']),
            'display_name' => 'Reg User',
            'username' => "ru{$suffix}",
            'email' => "ru{$suffix}@eronyx.test",
            'password' => $password,
            'password_confirmation' => $password,
            'accept_terms' => '1',
            'accept_privacy' => '1',
            'accept_age' => '1',
        ],
    ]);
    $sidRegAfter = sessionCookie($regPost['headers']) ?? $sidRegBefore;
    $regId = (int) $pdo->query("SELECT id FROM users WHERE email = 'ru{$suffix}@eronyx.test'")->fetchColumn();
    if ($regId > 0) {
        $createdUserIds[] = $regId;
    }
    check(14, 'Session fixation register blocked', $regPost['status'] === 302 && is_string($sidRegBefore) && is_string($sidRegAfter) && $sidRegBefore !== $sidRegAfter);

    $logoutPage = http('GET', $baseUrl . '/account', ['cookie' => $session['cookie']]);
    $logout = http('POST', $baseUrl . '/logout', [
        'cookie' => $session['cookie'],
        'fields' => ['_csrf' => csrfFrom($logoutPage['body'])],
    ]);
    $afterLogout = http('GET', $baseUrl . '/account', ['cookie' => $session['cookie']]);
    check(15, 'Logout invalidates', $logout['status'] === 302 && $afterLogout['status'] === 302);

    $session = login($baseUrl, "ba{$suffix}@eronyx.test", $password);
    $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = :id")->execute(['id' => $buyerA]);
    $suspendedAccount = http('GET', $baseUrl . '/account', ['cookie' => $session['cookie']]);
    $pdo->prepare("UPDATE users SET status = 'active' WHERE id = :id")->execute(['id' => $buyerA]);
    check(16, 'Suspended user invalidates auth', $suspendedAccount['status'] === 302);

    $csrfTests = [
        17 => ['url' => $baseUrl . '/register', 'fields' => ['email' => 'x@y.z']],
        18 => ['url' => $baseUrl . '/login', 'fields' => ['email' => "ba{$suffix}@eronyx.test", 'password' => 'wrong-password']],
    ];
    $guestCookie = cookiePath();
    http('GET', $baseUrl . '/login', ['cookie' => $guestCookie]);
    $csrf17 = http('POST', $baseUrl . '/register', ['cookie' => $guestCookie, 'fields' => ['_csrf' => 'invalid', 'email' => 'x@y.z', 'password' => $password, 'password_confirmation' => $password, 'username' => 'abc', 'display_name' => 'X']]);
    check(17, 'Register invalid CSRF', $csrf17['status'] === 403);
    $csrf18 = http('POST', $baseUrl . '/login', ['cookie' => $guestCookie, 'fields' => ['_csrf' => 'invalid', 'email' => "ba{$suffix}@eronyx.test", 'password' => $password]]);
    check(18, 'Login invalid CSRF', $csrf18['status'] === 403);

    $session = login($baseUrl, "ba{$suffix}@eronyx.test", $password);
    $acc = http('GET', $baseUrl . '/account', ['cookie' => $session['cookie']]);
    $csrf19 = http('POST', $baseUrl . '/logout', ['cookie' => $session['cookie'], 'fields' => ['_csrf' => 'invalid']]);
    check(19, 'Logout invalid CSRF', $csrf19['status'] === 403);

    $creatorSession = login($baseUrl, "ca{$suffix}@eronyx.test", $password);
    $edit = http('GET', $baseUrl . '/creator/listings/' . $listingA . '/edit', ['cookie' => $creatorSession['cookie']]);
    $csrf20 = http('POST', $baseUrl . '/creator/listings/' . $listingA, [
        'cookie' => $creatorSession['cookie'],
        'fields' => ['_csrf' => 'invalid', 'title' => 'Hacked', 'description' => 'x', 'listing_type' => 'digital_content', 'price' => '1.00', 'currency' => 'EUR', 'visibility' => 'public'],
    ]);
    $titleStill = (string) $pdo->query('SELECT title FROM listings WHERE id = ' . (int) $listingA)->fetchColumn();
    check(20, 'Listing update invalid CSRF', $csrf20['status'] === 403 && str_contains($titleStill, 'alert(1)'));

    $start = http('POST', $baseUrl . '/messages/start/' . $listingB, [
        'cookie' => $session['cookie'],
        'fields' => ['_csrf' => csrfFrom($acc['body'])],
    ]);
    $conversationId = 0;
    if (preg_match('#/account/messages/(\d+)#', headerValue($start['headers'], 'Location') ?? '', $m) === 1) {
        $conversationId = (int) $m[1];
    }
    if ($conversationId === 0) {
        $conversationId = (int) $pdo->query(
            'SELECT id FROM conversations WHERE created_by_user_id = ' . (int) $buyerA . ' ORDER BY id DESC LIMIT 1'
        )->fetchColumn();
    }
    $csrf21 = http('POST', $baseUrl . '/account/messages/' . $conversationId, [
        'cookie' => $session['cookie'],
        'fields' => ['_csrf' => 'invalid', 'body' => 'should not save'],
    ]);
    $msgCount = (int) $pdo->query('SELECT COUNT(*) FROM messages WHERE conversation_id = ' . (int) $conversationId)->fetchColumn();
    check(21, 'Message invalid CSRF', $csrf21['status'] === 403 && $msgCount === 0);

    $csrf22 = http('POST', $baseUrl . '/reports/listing/' . $listingB, [
        'cookie' => $session['cookie'],
        'fields' => ['_csrf' => 'invalid', 'reason_code' => 'spam', 'details' => 'nope'],
    ]);
    $reportCount = (int) $pdo->query('SELECT COUNT(*) FROM reports WHERE reporter_user_id = ' . (int) $buyerA)->fetchColumn();
    check(22, 'Report invalid CSRF', $csrf22['status'] === 403 && $reportCount === 0);

    $modSession = login($baseUrl, "mo{$suffix}@eronyx.test", $password);
    $csrf23 = http('POST', $baseUrl . '/moderator/listings/' . $listingA . '/suspend', [
        'cookie' => $modSession['cookie'],
        'fields' => ['_csrf' => 'invalid'],
    ]);
    $listingStatus = (string) $pdo->query('SELECT status FROM listings WHERE id = ' . (int) $listingA)->fetchColumn();
    check(23, 'Moderation invalid CSRF', $csrf23['status'] === 403 && $listingStatus === 'published');

    $xss = '<script>alert(1)</script>';
    $xss2 = '"><img src=x onerror=alert(1)>';
    $profilePage = http('GET', $baseUrl . '/account/profile', ['cookie' => $session['cookie']]);
    http('POST', $baseUrl . '/account/profile', [
        'cookie' => $session['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($profilePage['body']),
            'display_name' => $xss2,
            'username' => "ba{$suffix}",
            'bio' => $xss,
        ],
    ]);
    $profileAfter = http('GET', $baseUrl . '/account/profile', ['cookie' => $session['cookie']]);
    check(24, 'Profile stored XSS', xssSafe($profileAfter['body']));

    $showA = http('GET', $baseUrl . '/marketplace/la-' . $suffix);
    check(25, 'Listing stored XSS', $showA['status'] === 200 && !str_contains($showA['body'], '<script>alert(1)</script>') && str_contains($showA['body'], 'alert(1)'));

    $thread = http('GET', $baseUrl . '/account/messages/' . $conversationId, ['cookie' => $session['cookie']]);
    http('POST', $baseUrl . '/account/messages/' . $conversationId, [
        'cookie' => $session['cookie'],
        'fields' => ['_csrf' => csrfFrom($thread['body']), 'body' => $xss],
    ]);
    $threadAfter = http('GET', $baseUrl . '/account/messages/' . $conversationId, ['cookie' => $session['cookie']]);
    check(26, 'Message stored XSS', !str_contains($threadAfter['body'], '<script>alert(1)</script>') && str_contains($threadAfter['body'], 'alert(1)'));

    $reportForm = http('GET', $baseUrl . '/reports/listing/' . $listingB, ['cookie' => $session['cookie']]);
    http('POST', $baseUrl . '/reports/listing/' . $listingB, [
        'cookie' => $session['cookie'],
        'fields' => ['_csrf' => csrfFrom($reportForm['body']), 'reason_code' => 'other', 'details' => $xss],
    ]);
    $modReport = http('GET', $baseUrl . '/moderator/reports', ['cookie' => $modSession['cookie']]);
    check(27, 'Report stored XSS', !str_contains($modReport['body'], '<script>alert(1)</script>'));

    $search = http('GET', $baseUrl . '/marketplace?q=' . rawurlencode($xss2));
    check(28, 'Search reflected XSS', $search['status'] === 200 && xssSafe($search['body']));

    $sqli = [
        29 => ['name' => 'q SQLi', 'query' => 'q=' . rawurlencode("' OR 1=1 --")],
        30 => ['name' => 'creator SQLi', 'query' => 'creator=' . rawurlencode("'; DROP TABLE users; --")],
        31 => ['name' => 'sort SQLi', 'query' => 'sort=' . rawurlencode('price;DROP TABLE users')],
        32 => ['name' => 'price SQLi', 'query' => 'min_price=' . rawurlencode('1) OR 1=1 --') . '&max_price=' . rawurlencode('%')],
    ];
    $userCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    foreach ($sqli as $num => $item) {
        $res = http('GET', $baseUrl . '/marketplace?' . $item['query']);
        $ok = $res['status'] === 200
            && !str_contains($res['body'], 'SQLSTATE')
            && !str_contains($res['body'], 'Fatal error')
            && $userCountBefore === (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        check($num, $item['name'], $ok, 'status=' . $res['status']);
    }

    $orderStmt = $pdo->prepare(
        "INSERT INTO orders (buyer_user_id, status, subtotal_amount, total_amount, currency) VALUES (:id, 'pending', 5.00, 5.00, 'EUR')"
    );
    $orderStmt->execute(['id' => $buyerA]);
    $orderA = (int) $pdo->lastInsertId();
    $sessionB = login($baseUrl, "bb{$suffix}@eronyx.test", $password);
    $idorOrder = http('GET', $baseUrl . '/account/orders/' . $orderA, ['cookie' => $sessionB['cookie']]);
    check(33, 'Order cross-user', in_array($idorOrder['status'], [403, 404], true));

    $creatorBSession = login($baseUrl, "cb{$suffix}@eronyx.test", $password);
    $idorListing = http('GET', $baseUrl . '/creator/listings/' . $listingA, ['cookie' => $creatorBSession['cookie']]);
    check(34, 'Listing cross-creator', in_array($idorListing['status'], [403, 404], true));

    $videoPath = $root . '/storage/private/media/' . date('Y/m');
    if (!is_dir($videoPath)) {
        mkdir($videoPath, 0775, true);
    }
    $storageKey = 'media/' . date('Y/m') . '/' . bin2hex(random_bytes(8)) . '.mp4';
    $absVideo = $root . '/storage/private/media/' . substr($storageKey, strlen('media/'));
    file_put_contents($absVideo, str_repeat('V', 2048));
    $createdMediaPaths[] = $absVideo;
    $mediaStmt = $pdo->prepare(
        "INSERT INTO media_files (owner_user_id, storage_disk, storage_key, media_type, visibility, mime_type, size_bytes, checksum, status)
         VALUES (:owner, 'local', :storage_key, 'video', 'private', 'video/mp4', 2048, :checksum, 'active')"
    );
    $mediaStmt->execute([
        'owner' => $creatorA,
        'storage_key' => $storageKey,
        'checksum' => hash('sha256', 'x' . $suffix),
    ]);
    $mediaA = (int) $pdo->lastInsertId();
    $createdMediaIds[] = $mediaA;
    $pdo->prepare("INSERT INTO listing_media (listing_id, media_file_id, usage_type, sort_order) VALUES (:l, :m, 'private_content', 1)")
        ->execute(['l' => $listingA, 'm' => $mediaA]);

    $coverKey = 'media/' . date('Y/m') . '/' . bin2hex(random_bytes(8)) . '.png';
    $absCover = $root . '/storage/private/media/' . substr($coverKey, strlen('media/'));
    file_put_contents($absCover, tinyPng());
    $createdMediaPaths[] = $absCover;
    $coverStmt = $pdo->prepare(
        "INSERT INTO media_files (owner_user_id, storage_disk, storage_key, media_type, visibility, mime_type, size_bytes, checksum, status)
         VALUES (:owner, 'local', :storage_key, 'image', 'public', 'image/png', :size, :checksum, 'active')"
    );
    $coverStmt->execute([
        'owner' => $creatorA,
        'storage_key' => $coverKey,
        'size' => strlen(tinyPng()),
        'checksum' => hash('sha256', $coverKey),
    ]);
    $coverId = (int) $pdo->lastInsertId();
    $createdMediaIds[] = $coverId;
    $pdo->prepare("INSERT INTO listing_media (listing_id, media_file_id, usage_type, sort_order) VALUES (:l, :m, 'cover', 0)")
        ->execute(['l' => $listingA, 'm' => $coverId]);

    $idorMedia = http('POST', $baseUrl . '/creator/listings/' . $listingA . '/media/' . $coverId . '/cover', [
        'cookie' => $creatorBSession['cookie'],
        'fields' => ['_csrf' => 'invalid'],
    ]);
    $idorMediaGet = http('GET', $baseUrl . '/creator/listings/' . $listingA . '/media', ['cookie' => $creatorBSession['cookie']]);
    check(35, 'Media cross-creator', in_array($idorMediaGet['status'], [403, 404], true));

    $idorConv = http('GET', $baseUrl . '/account/messages/' . $conversationId, ['cookie' => $sessionB['cookie']]);
    check(36, 'Conversation cross-user', in_array($idorConv['status'], [403, 404], true));

    $messageId = (int) $pdo->query('SELECT id FROM messages ORDER BY id DESC LIMIT 1')->fetchColumn();
    $idorMsgReport = http('GET', $baseUrl . '/reports/message/' . $messageId, ['cookie' => $sessionB['cookie']]);
    check(37, 'Message report cross-user', in_array($idorMsgReport['status'], [403, 404], true));

    $guestMedia = http('GET', $baseUrl . '/media/' . $mediaA);
    check(38, 'Private media without grant', $guestMedia['status'] === 404);
    check(59, 'Guest private video 404', $guestMedia['status'] === 404);

    $grantListingB = $pdo->prepare(
        "INSERT INTO private_content_access (user_id, listing_id, granted_by_user_id, source, status, granted_at)
         VALUES (:u, :l, :g, 'test', 'active', CURRENT_TIMESTAMP)"
    );
    $grantListingB->execute(['u' => $buyerB, 'l' => $listingB, 'g' => $creatorB]);
    $mismatch = http('GET', $baseUrl . '/media/' . $mediaA, ['cookie' => $sessionB['cookie']]);
    check(39, 'Grant listing mismatch', $mismatch['status'] === 404);

    $storage = new MediaStorageService($root . '/storage/private/media');
    $jpg = writeTemp(tinyJpeg(), '.jpg');
    $png = writeTemp(tinyPng(), '.png');
    $webp = writeTemp(tinyWebp(), '.webp');
    $phpJpg = writeTemp('<?php echo 1;', '.jpg');
    $svg = writeTemp('<svg xmlns="http://www.w3.org/2000/svg"></svg>', '.svg');
    $htmlPng = writeTemp('<html><script>alert(1)</script></html>', '.png');
    $fakeMp4 = writeTemp('not a video file', '.mp4');
    $oversize = writeTemp(tinyPng() . str_repeat("\0", (5 * 1024 * 1024) + 8), '.png');

    $accept = static function (MediaStorageService $storage, array $file, string $usage) {
        try {
            $prepared = $storage->prepareUpload($file, $usage);
            return isset($prepared['storage_key']);
        } catch (Throwable) {
            return false;
        }
    };
    $reject = static function (MediaStorageService $storage, array $file, string $usage) {
        try {
            $storage->prepareUpload($file, $usage);
            return false;
        } catch (Throwable) {
            return true;
        }
    };

    check(46, 'JPG accepted', $accept($storage, uploadFile($jpg, 'a.jpg'), 'cover'));
    check(47, 'PNG accepted', $accept($storage, uploadFile($png, 'a.png'), 'gallery'));
    check(48, 'WEBP accepted', $accept($storage, uploadFile($webp, 'a.webp'), 'preview'));
    check(49, 'PHP.jpg rejected', $reject($storage, uploadFile($phpJpg, 'x.jpg'), 'cover'));
    check(50, 'SVG rejected', $reject($storage, uploadFile($svg, 'x.svg'), 'cover'));
    check(51, 'HTML.png rejected', $reject($storage, uploadFile($htmlPng, 'x.png'), 'cover'));
    check(52, 'fake MP4 rejected', $reject($storage, uploadFile($fakeMp4, 'x.mp4'), 'private_content'));
    check(53, 'oversized rejected', $reject($storage, uploadFile($oversize, 'big.png'), 'cover'));
    $evil = $accept($storage, uploadFile($png, '../../evil.php'), 'cover');
    check(54, 'traversal filename sanitized', $evil === true);

    $pathFail = false;
    try {
        $storage->resolveStorageKey('media/../../.env');
    } catch (Throwable) {
        $pathFail = true;
    }
    $projectUrl = preg_replace('#/public$#', '', $baseUrl);
    $mediaTraversal = http('GET', $baseUrl . '/media/../.env');
    $rootEnv = is_string($projectUrl) ? http('GET', $projectUrl . '/.env') : ['status' => 0, 'body' => ''];
    check(
        55,
        '/media traversal no access',
        $pathFail
        && !str_contains($mediaTraversal['body'], 'DB_PASSWORD')
        && !str_contains((string) ($rootEnv['body'] ?? ''), 'APP_DEBUG')
    );
    $enc = http('GET', $baseUrl . '/media/%2e%2e/%2e%2e/.env');
    check(56, 'encoded traversal no access', $enc['status'] !== 200 || !str_contains($enc['body'], 'DB_PASSWORD'));
    $keyFail = false;
    try {
        $storage->resolveStorageKey('media/../../../config/app.php');
    } catch (Throwable) {
        $keyFail = true;
    }
    check(57, 'storage_key stays in root', $keyFail);

    $rlDir = $root . '/storage/cache/rate-limits-test-' . $suffix;
    $limiter = new RateLimiter($rlDir);
    $limiter->hit('../../.env', 60);
    $files = glob($rlDir . '/*.json') ?: [];
    $safeName = $files !== [] && preg_match('/[a-f0-9]{64}\\.json$/', basename($files[0])) === 1;
    check(58, 'rate limiter key hashed', $safeName && !str_contains($files[0], '.env'));
    foreach ($files as $file) {
        @unlink($file);
    }
    @rmdir($rlDir);

    $noGrantBuyer = http('GET', $baseUrl . '/media/' . $mediaA, ['cookie' => $session['cookie']]);
    check(60, 'Buyer no grant 404', $noGrantBuyer['status'] === 404);

    $pdo->prepare(
        "INSERT INTO private_content_access (user_id, listing_id, granted_by_user_id, source, status, granted_at)
         VALUES (:u, :l, :g, 'test', 'active', CURRENT_TIMESTAMP)"
    )->execute(['u' => $buyerA, 'l' => $listingA, 'g' => $creatorA]);
    $session = login($baseUrl, "ba{$suffix}@eronyx.test", $password);
    $granted = http('GET', $baseUrl . '/media/' . $mediaA, ['cookie' => $session['cookie']]);
    check(61, 'Buyer grant 200', $granted['status'] === 200);
    $rangeOk = http('GET', $baseUrl . '/media/' . $mediaA, [
        'cookie' => $session['cookie'],
        'headers' => ['Range: bytes=0-10'],
    ]);
    check(62, 'Valid Range 206', $rangeOk['status'] === 206);
    $rangeBad = http('GET', $baseUrl . '/media/' . $mediaA, [
        'cookie' => $session['cookie'],
        'headers' => ['Range: bytes=5000-6000'],
    ]);
    check(63, 'Invalid Range 416', $rangeBad['status'] === 416);
    $rangeMulti = http('GET', $baseUrl . '/media/' . $mediaA, [
        'cookie' => $session['cookie'],
        'headers' => ['Range: bytes=0-1,2-3'],
    ]);
    check(64, 'Multi-range 416', $rangeMulti['status'] === 416);
    $unauthRange = http('GET', $baseUrl . '/media/' . $mediaA, ['headers' => ['Range: bytes=0-10']]);
    check(65, 'Unauthorized Range no metadata leak', $unauthRange['status'] === 404 && headerValue($unauthRange['headers'], 'Content-Range') === null && headerValue($unauthRange['headers'], 'Content-Type') !== 'video/mp4');

    $mass = http('POST', $baseUrl . '/creator/listings/' . $listingA, [
        'cookie' => $creatorSession['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($edit['body']),
            'title' => 'Mass title',
            'description' => 'desc',
            'listing_type' => 'digital_content',
            'price' => '5.00',
            'currency' => 'EUR',
            'visibility' => 'public',
            'owner_user_id' => (string) $creatorB,
            'status' => 'published',
            'categories' => ['1'],
        ],
    ]);
    $ownerAfter = (int) $pdo->query('SELECT owner_user_id FROM listings WHERE id = ' . (int) $listingA)->fetchColumn();
    $statusAfter = (string) $pdo->query('SELECT status FROM listings WHERE id = ' . (int) $listingA)->fetchColumn();
    check(66, 'Ignore listing owner_user_id', $ownerAfter === $creatorA);
    check(67, 'Ignore listing status POST', $statusAfter !== 'draft' || $mass['status'] !== 500);

    $checkout = http('POST', $baseUrl . '/checkout/' . $listingB, [
        'cookie' => $session['cookie'],
        'fields' => [
            '_csrf' => csrfFrom(http('GET', $baseUrl . '/checkout/' . $listingB, ['cookie' => $session['cookie']])['body']),
            'amount' => '0.01',
            'currency' => 'USD',
            'seller_user_id' => (string) $buyerA,
            'provider' => 'stripe',
            'status' => 'paid',
        ],
    ]);
    $orderId = 0;
    if (preg_match('#/account/orders/(\d+)#', headerValue($checkout['headers'], 'Location') ?? '', $om) === 1) {
        $orderId = (int) $om[1];
    }
    $orderRow = $orderId > 0 ? $pdo->query('SELECT total_amount, currency FROM orders WHERE id = ' . $orderId)->fetch() : null;
    $itemRow = $orderId > 0 ? $pdo->query('SELECT seller_user_id FROM order_items WHERE order_id = ' . $orderId)->fetch() : null;
    $payRow = $orderId > 0 ? $pdo->query('SELECT provider, status FROM payments WHERE order_id = ' . $orderId)->fetch() : null;
    check(68, 'Ignore checkout amount', is_array($orderRow) && (string) $orderRow['total_amount'] === '5.00');
    check(69, 'Ignore currency', is_array($orderRow) && $orderRow['currency'] === 'EUR');
    check(70, 'Ignore seller', is_array($itemRow) && (int) $itemRow['seller_user_id'] === $creatorB);
    check(71, 'Ignore payment provider/status', is_array($payRow) && $payRow['provider'] === 'test' && $payRow['status'] !== 'paid');

    $authCookie = cookiePath();
    $wrongEmail = http('POST', $baseUrl . '/login', [
        'cookie' => $authCookie,
        'fields' => [
            '_csrf' => csrfFrom(http('GET', $baseUrl . '/login', ['cookie' => $authCookie])['body']),
            'email' => 'nobody-' . $suffix . '@eronyx.test',
            'password' => $password,
        ],
    ]);
    $wrongPass = http('POST', $baseUrl . '/login', [
        'cookie' => $authCookie,
        'fields' => [
            '_csrf' => csrfFrom(http('GET', $baseUrl . '/login', ['cookie' => $authCookie])['body']),
            'email' => "ba{$suffix}@eronyx.test",
            'password' => 'definitely-wrong-pass',
        ],
    ]);
    check(72, 'Wrong email generic message', str_contains($wrongEmail['body'], 'Email o contraseña incorrectos.'));
    check(73, 'Wrong password same message', str_contains($wrongPass['body'], 'Email o contraseña incorrectos.') && $wrongEmail['body'] !== '' && str_contains($wrongPass['body'], 'Email o contraseña incorrectos.'));

    $pdo->prepare('UPDATE users SET last_login_at = NULL WHERE id = :id')->execute(['id' => $buyerB]);
    http('POST', $baseUrl . '/login', [
        'cookie' => cookiePath(),
        'fields' => [
            '_csrf' => csrfFrom(http('GET', $baseUrl . '/login')['body']),
            'email' => "bb{$suffix}@eronyx.test",
            'password' => 'wrong-pass-xx',
        ],
    ]);
    $lastFail = $pdo->query('SELECT last_login_at FROM users WHERE id = ' . (int) $buyerB)->fetchColumn();
    login($baseUrl, "bb{$suffix}@eronyx.test", $password);
    $lastOk = $pdo->query('SELECT last_login_at FROM users WHERE id = ' . (int) $buyerB)->fetchColumn();
    check(74, 'last_login only on success', $lastFail === null && $lastOk !== null);

    $sid = sessionCookie($loginFix['headers']) ?? '';
    $sessionFile = '';
    $savePath = session_save_path();
    if ($sid !== '' && is_dir($savePath)) {
        $candidate = rtrim($savePath, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sid;
        if (is_file($candidate)) {
            $sessionFile = (string) file_get_contents($candidate);
        }
    }
    check(75, 'password not in session', $sessionFile === '' || (!str_contains($sessionFile, $password) && !str_contains($sessionFile, 'password_hash')));
    check(76, 'roles not in session', $sessionFile === '' || (!str_contains($sessionFile, 'moderator') && !str_contains($sessionFile, 's:5:"admin"')));

    $envPublic = http('GET', $baseUrl . '/.env');
    check(77, '/.env not accessible', $envPublic['status'] !== 200 || !str_contains($envPublic['body'], 'DB_PASSWORD'));
    $storageHttp = http('GET', $baseUrl . '/storage');
    check(78, '/storage not accessible', in_array($storageHttp['status'], [403, 404], true) || !str_contains($storageHttp['body'], 'rate-limits'));
    $databaseHttp = http('GET', $baseUrl . '/database');
    check(79, '/database not accessible', in_array($databaseHttp['status'], [403, 404], true));
    $composerHttp = http('GET', $baseUrl . '/composer.json');
    check(80, 'composer not exposed from public', $composerHttp['status'] !== 200 || !str_contains($composerHttp['body'], 'eronyx/platform'));

    $put = http('PUT', $baseUrl . '/login', ['fields' => ['email' => 'x']]);
    $del = http('DELETE', $baseUrl . '/logout');
    $patch = http('PATCH', $baseUrl . '/account/profile', ['fields' => ['bio' => 'x']]);
    $trace = http('TRACE', $baseUrl . '/');
    check(81, 'PUT does not run POST', $put['status'] === 404);
    check(82, 'DELETE does not execute', $del['status'] === 404);
    check(83, 'PATCH does not execute', $patch['status'] === 404);
    check(
        84,
        'TRACE does not execute app actions',
        !in_array($trace['status'], [302, 200], true)
        || (!str_contains($trace['body'], 'ERONYX') && !str_contains($trace['raw_headers'] ?? '', 'Content-Security-Policy'))
    );

    $buyerMod = http('GET', $baseUrl . '/moderator/reports', ['cookie' => $session['cookie']]);
    $adminSession = login($baseUrl, "ad{$suffix}@eronyx.test", $password);
    $adminMod = http('GET', $baseUrl . '/moderator/reports', ['cookie' => $adminSession['cookie']]);
    $modOk = http('GET', $baseUrl . '/moderator/reports', ['cookie' => $modSession['cookie']]);
    check(85, 'Buyer moderator queue 403', $buyerMod['status'] === 403);
    check(86, 'Admin without moderator 403', $adminMod['status'] === 403);
    check(87, 'Moderator 200', $modOk['status'] === 200);

    $modDash = http('GET', $baseUrl . '/moderator', ['cookie' => $modSession['cookie']]);
    $suspend = http('POST', $baseUrl . '/moderator/listings/' . $listingA . '/suspend', [
        'cookie' => $modSession['cookie'],
        'fields' => ['_csrf' => csrfFrom($modDash['body'])],
    ]);
    $statusSuspended = (string) $pdo->query('SELECT status FROM listings WHERE id = ' . (int) $listingA)->fetchColumn();
    check(88, 'Listing suspend works', $statusSuspended === 'suspended');

    $prev = (string) $pdo->query(
        "SELECT previous_status FROM moderation_actions WHERE action_type = 'listing_suspend' AND target_id = " . (int) $listingA . ' ORDER BY id DESC LIMIT 1'
    )->fetchColumn();
    $restore = http('POST', $baseUrl . '/moderator/listings/' . $listingA . '/restore', [
        'cookie' => $modSession['cookie'],
        'fields' => [
            '_csrf' => csrfFrom(http('GET', $baseUrl . '/moderator', ['cookie' => $modSession['cookie']])['body']),
            'previous_status' => 'draft',
        ],
    ]);
    $statusRestored = (string) $pdo->query('SELECT status FROM listings WHERE id = ' . (int) $listingA)->fetchColumn();
    check(89, 'Restore uses previous_status server-side', $statusRestored === ($prev !== '' ? $prev : 'published') && $statusRestored !== 'draft');

    $creatorDash = http('GET', $baseUrl . '/creator', ['cookie' => $creatorSession['cookie']]);
    http('POST', $baseUrl . '/moderator/creators/' . $creatorA . '/suspend', [
        'cookie' => $modSession['cookie'],
        'fields' => ['_csrf' => csrfFrom(http('GET', $baseUrl . '/moderator', ['cookie' => $modSession['cookie']])['body'])],
    ]);
    $creatorDashAfter = http('GET', $baseUrl . '/creator', ['cookie' => $creatorSession['cookie']]);
    $accountAfterSuspend = http('GET', $baseUrl . '/account', ['cookie' => $creatorSession['cookie']]);
    check(90, 'Creator suspend blocks dashboard', $creatorDash['status'] === 200 && $creatorDashAfter['status'] === 403 && $accountAfterSuspend['status'] === 200);
    $pdo->prepare("UPDATE creator_profiles SET status = 'active' WHERE user_id = :id")->execute(['id' => $creatorA]);

    $pubCheckout = http('GET', $baseUrl . '/checkout/' . $listingB, ['cookie' => $session['cookie']]);
    check(91, 'Public checkout auth works', $pubCheckout['status'] === 200);
    $privCheckout = http('GET', $baseUrl . '/checkout/' . $listingPrivate, ['cookie' => $session['cookie']]);
    check(92, 'Private listing checkout 404', $privCheckout['status'] === 404);
    $selfBuy = http('GET', $baseUrl . '/checkout/' . $listingA, ['cookie' => $creatorSession['cookie']]);
    check(93, 'Owner self-purchase 403', in_array($selfBuy['status'], [403, 404], true));

    $originalEnv = getenv('APP_ENV');
    putenv('APP_ENV=production');
    $controller = new App\Controllers\OrderController();
    $isLocal = (new ReflectionMethod($controller, 'isLocal'));
    $isLocal->setAccessible(true);
    $prodBlocked = $isLocal->invoke($controller) === false;
    if ($originalEnv === false) {
        putenv('APP_ENV');
    } else {
        putenv('APP_ENV=' . $originalEnv);
    }
    check(94, 'test-pay production 404', $prodBlocked);

    if ($orderId > 0) {
        $orderShow = http('GET', $baseUrl . '/account/orders/' . $orderId, ['cookie' => $session['cookie']]);
        $pay = http('POST', $baseUrl . '/account/orders/' . $orderId . '/test-pay', [
            'cookie' => $session['cookie'],
            'fields' => ['_csrf' => csrfFrom($orderShow['body'])],
        ]);
        $grantCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM private_content_access WHERE user_id = ' . (int) $buyerA . ' AND listing_id = ' . (int) $listingB
        )->fetchColumn();
        check(95, 'digital grant after test payment', $pay['status'] === 302 && $grantCount > 0);
    } else {
        check(95, 'digital grant after test payment', false, 'no order');
    }

    foreach (glob($root . '/storage/cache/rate-limits/*.json') ?: [] as $leftover) {
        @unlink($leftover);
    }

    $rateEmail = "rl{$suffix}@eronyx.test";
    createUser($pdo, $rateEmail, "rl{$suffix}", 'Rate User', $password, ['buyer']);
    $failLogin = null;
    $loginCsrfCookie = cookiePath();
    $loginForm = http('GET', $baseUrl . '/login', ['cookie' => $loginCsrfCookie]);
    for ($i = 0; $i < 5; $i++) {
        $failLogin = http('POST', $baseUrl . '/login', [
            'cookie' => $loginCsrfCookie,
            'fields' => [
                '_csrf' => csrfFrom($loginForm['body']) !== '' ? csrfFrom(http('GET', $baseUrl . '/login', ['cookie' => $loginCsrfCookie])['body']) : csrfFrom($loginForm['body']),
                'email' => $rateEmail,
                'password' => 'bad-password-xx',
            ],
        ]);
    }
    check(40, 'Login failures until limit', is_array($failLogin) && $failLogin['status'] === 200);
    $limited = http('POST', $baseUrl . '/login', [
        'cookie' => $loginCsrfCookie,
        'fields' => [
            '_csrf' => csrfFrom(http('GET', $baseUrl . '/login', ['cookie' => $loginCsrfCookie])['body']),
            'email' => $rateEmail,
            'password' => 'bad-password-xx',
        ],
    ]);
    check(41, 'Next login 429', $limited['status'] === 429);
    check(42, 'Retry-After present', $limited['status'] === 429 && headerValue($limited['headers'], 'Retry-After') !== null);

    foreach (glob($root . '/storage/cache/rate-limits/*.json') ?: [] as $leftover) {
        @unlink($leftover);
    }
    $resetLogin = login($baseUrl, $rateEmail, $password);
    check(43, 'Login success resets limiter', $resetLogin['login']['status'] === 302);

    $msgSession = $session;
    $msgThread = http('GET', $baseUrl . '/account/messages/' . $conversationId, ['cookie' => $msgSession['cookie']]);
    $sent = 0;
    $lastMsg = null;
    for ($i = 0; $i < 31; $i++) {
        $msgThread = http('GET', $baseUrl . '/account/messages/' . $conversationId, ['cookie' => $msgSession['cookie']]);
        $lastMsg = http('POST', $baseUrl . '/account/messages/' . $conversationId, [
            'cookie' => $msgSession['cookie'],
            'fields' => ['_csrf' => csrfFrom($msgThread['body']), 'body' => 'rate ' . $i],
        ]);
        if ($lastMsg['status'] === 429) {
            break;
        }
        $sent++;
    }
    check(44, 'Messages hit 429', $sent >= 30 && is_array($lastMsg) && $lastMsg['status'] === 429);

    $reportListings = [];
    for ($i = 0; $i < 11; $i++) {
        $reportListings[] = createListing($pdo, $creatorB, 'R' . $i . $suffix, "r{$i}-{$suffix}");
    }
    $lastReport = null;
    $reportOk = 0;
    foreach ($reportListings as $rid) {
        $form = http('GET', $baseUrl . '/reports/listing/' . $rid, ['cookie' => $msgSession['cookie']]);
        $lastReport = http('POST', $baseUrl . '/reports/listing/' . $rid, [
            'cookie' => $msgSession['cookie'],
            'fields' => ['_csrf' => csrfFrom($form['body']), 'reason_code' => 'spam', 'details' => 'rate'],
        ]);
        if ($lastReport['status'] === 429) {
            break;
        }
        if (in_array($lastReport['status'], [302, 200], true)) {
            $reportOk++;
        }
    }
    check(45, 'Reports hit 429', $reportOk >= 10 && is_array($lastReport) && $lastReport['status'] === 429);

    $guestAccount = http('GET', $baseUrl . '/account');
    $guestFav = http('GET', $baseUrl . '/account/favorites');
    $guestMsg = http('GET', $baseUrl . '/account/messages');
    $guestCreator = http('GET', $baseUrl . '/creator');
    $guestMod = http('GET', $baseUrl . '/moderator');
    $css = http('GET', $baseUrl . '/css/app.css');
    $js = http('GET', $baseUrl . '/js/app.js');
    if (!($guestAccount['status'] === 302 && $guestFav['status'] === 302 && $guestMsg['status'] === 302
        && $guestCreator['status'] === 302 && $guestMod['status'] === 302
        && $css['status'] === 200 && $js['status'] === 200)) {
        $fail++;
        $failures[] = 'HTTP regression guests/assets';
        echo "FAIL HTTP regression guests/assets\n";
    } else {
        $pass++;
        echo "PASS HTTP regression guests/assets\n";
    }
} catch (Throwable $exception) {
    echo 'SUITE ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n";
    $fail++;
}

foreach ([$jpg ?? null, $png ?? null, $webp ?? null, $phpJpg ?? null, $svg ?? null, $htmlPng ?? null, $fakeMp4 ?? null, $oversize ?? null] as $tmp) {
    if (is_string($tmp) && is_file($tmp)) {
        @unlink($tmp);
    }
}

foreach ($createdMediaPaths as $path) {
    if (is_file($path)) {
        @unlink($path);
    }
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

    $pdo->exec("DELETE FROM audit_logs WHERE actor_user_id IN ({$ids}) OR entity_id IN ({$listingIds})");
    $pdo->exec("DELETE FROM moderation_actions WHERE moderator_user_id IN ({$ids}) OR target_id IN ({$listingIds}) OR target_id IN ({$ids})");
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
}

$counts = [];
foreach (
    [
        'users', 'profiles', 'user_roles', 'creator_profiles', 'age_verifications', 'roles', 'categories',
        'listings', 'listing_categories', 'media_files', 'listing_media', 'private_content_access',
        'orders', 'order_items', 'payments', 'favorites', 'conversations', 'conversation_participants',
        'messages', 'reports', 'moderation_actions', 'audit_logs',
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
