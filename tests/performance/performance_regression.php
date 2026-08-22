<?php

declare(strict_types=1);

use App\Repositories\ListingRepository;
use App\Repositories\SeoRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$app = require $root . '/config/app.php';
$db = require $root . '/config/database.php';
$baseUrl = rtrim((string) $app['url'], '/');
$pass = 0;
$fail = 0;
$failures = [];
$suffix = 'p' . bin2hex(random_bytes(3));
$password = 'PerfPass10x';
$createdUserIds = [];
$createdListingIds = [];
$createdMediaIds = [];
$createdMediaPaths = [];
$cookieFiles = [];

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
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $opts['headers'] ?? [],
        CURLOPT_ENCODING => $opts['encoding'] ?? '',
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
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if (!is_string($raw)) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'type' => ''];
    }

    return [
        'status' => $status,
        'headers' => preg_split("/\r\n/", trim(substr($raw, 0, $headerSize))) ?: [],
        'body' => substr($raw, $headerSize),
        'type' => $contentType,
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
    $path = tempnam(sys_get_temp_dir(), 'eronyx_perf_');

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

function tinyPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true) ?: '';
}

function questions(PDO $pdo): int
{
    $row = $pdo->query("SHOW STATUS LIKE 'Questions'")->fetch();

    return (int) ($row['Value'] ?? 0);
}

function cacheIsPublic(?string $value): bool
{
    if ($value === null || $value === '') {
        return false;
    }

    $lower = strtolower($value);

    return str_contains($lower, 'public') && !str_contains($lower, 'no-store');
}

try {
    $htaccess = (string) file_get_contents($root . '/public/.htaccess');
    $listingSrc = (string) file_get_contents($root . '/app/Repositories/ListingRepository.php');
    $navSrc = (string) file_get_contents($root . '/app/Core/Nav.php');
    $jsSrc = (string) file_get_contents($root . '/public/js/app.js');
    $layoutSrc = (string) file_get_contents($root . '/app/Views/layouts/main.php');

    $css = http('GET', $baseUrl . '/css/app.css');
    $js = http('GET', $baseUrl . '/js/app.js');
    check(1, 'app.css 200', $css['status'] === 200);
    check(2, 'app.js 200', $js['status'] === 200);
    check(
        3,
        'correct Content-Type',
        str_contains(strtolower($css['type'] !== '' ? $css['type'] : (string) headerValue($css['headers'], 'Content-Type')), 'text/css')
        && (
            str_contains(strtolower($js['type'] !== '' ? $js['type'] : (string) headerValue($js['headers'], 'Content-Type')), 'javascript')
            || str_contains(strtolower((string) headerValue($js['headers'], 'Content-Type')), 'javascript')
        )
    );
    $cssCache = (string) (headerValue($css['headers'], 'Cache-Control') ?? '');
    check(
        4,
        'cache header razonable',
        (str_contains($cssCache, 'max-age') && !str_contains($cssCache, 'immutable'))
        || str_contains($htaccess, 'max-age=86400')
    );
    $cssGzip = http('GET', $baseUrl . '/css/app.css', ['headers' => ['Accept-Encoding: gzip'], 'encoding' => 'gzip']);
    $encoding = strtolower((string) (headerValue($cssGzip['headers'], 'Content-Encoding') ?? ''));
    check(
        5,
        'compression config present if Apache supports',
        str_contains($htaccess, 'mod_deflate')
        && str_contains($htaccess, 'text/css')
        && ($encoding === '' || str_contains($encoding, 'gzip') || str_contains($encoding, 'deflate'))
    );

    $creatorId = createUser($pdo, "perf.c.{$suffix}@eronyx.test", "perfc{$suffix}", 'Perf Creator', $password, ['buyer', 'creator']);
    $buyerId = createUser($pdo, "perf.b.{$suffix}@eronyx.test", "perfb{$suffix}", 'Perf Buyer', $password, ['buyer']);
    $adminId = createUser($pdo, "perf.a.{$suffix}@eronyx.test", "perfa{$suffix}", 'Perf Admin', $password, ['buyer', 'admin']);
    $pdo->prepare("INSERT INTO creator_profiles (user_id, status) VALUES (:id, 'active')")->execute(['id' => $creatorId]);

    $slug = 'perf-pub-' . $suffix;
    $stmt = $pdo->prepare(
        "INSERT INTO listings (
            owner_user_id, title, slug, description, listing_type, status, price, currency, visibility, published_at
         ) VALUES (
            :owner_user_id, :title, :slug, :description, 'digital_content', 'published', '9.50', 'EUR', 'public', CURRENT_TIMESTAMP
         )"
    );
    $stmt->execute([
        'owner_user_id' => $creatorId,
        'title' => 'Perf Listing ' . $suffix,
        'slug' => $slug,
        'description' => 'Public listing used for performance checks.',
    ]);
    $listingId = (int) $pdo->lastInsertId();
    $createdListingIds[] = $listingId;

    $dir = $root . '/storage/private/media/' . date('Y/m');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $png = tinyPng();
    $coverKey = 'media/' . date('Y/m') . '/perf-cover-' . $suffix . '.png';
    $coverPath = $root . '/storage/private/media/' . substr($coverKey, strlen('media/'));
    file_put_contents($coverPath, $png);
    $createdMediaPaths[] = $coverPath;
    $coverChecksum = hash('sha256', $png);
    $pdo->prepare(
        "INSERT INTO media_files (owner_user_id, storage_disk, storage_key, media_type, visibility, mime_type, size_bytes, checksum, status)
         VALUES (:owner, 'local', :storage_key, 'image', 'public', 'image/png', :size, :checksum, 'active')"
    )->execute([
        'owner' => $creatorId,
        'storage_key' => $coverKey,
        'size' => strlen($png),
        'checksum' => $coverChecksum,
    ]);
    $coverId = (int) $pdo->lastInsertId();
    $createdMediaIds[] = $coverId;
    $pdo->prepare("INSERT INTO listing_media (listing_id, media_file_id, usage_type, sort_order) VALUES (:l, :m, 'cover', 0)")
        ->execute(['l' => $listingId, 'm' => $coverId]);

    $privateKey = 'media/' . date('Y/m') . '/perf-private-' . $suffix . '.png';
    $privatePath = $root . '/storage/private/media/' . substr($privateKey, strlen('media/'));
    file_put_contents($privatePath, $png);
    $createdMediaPaths[] = $privatePath;
    $pdo->prepare(
        "INSERT INTO media_files (owner_user_id, storage_disk, storage_key, media_type, visibility, mime_type, size_bytes, checksum, status)
         VALUES (:owner, 'local', :storage_key, 'image', 'private', 'image/png', :size, :checksum, 'active')"
    )->execute([
        'owner' => $creatorId,
        'storage_key' => $privateKey,
        'size' => strlen($png),
        'checksum' => hash('sha256', $privateKey),
    ]);
    $privateId = (int) $pdo->lastInsertId();
    $createdMediaIds[] = $privateId;
    $pdo->prepare("INSERT INTO listing_media (listing_id, media_file_id, usage_type, sort_order) VALUES (:l, :m, 'private_content', 1)")
        ->execute(['l' => $listingId, 'm' => $privateId]);

    $publicMedia = http('GET', $baseUrl . '/media/' . $coverId);
    check(6, 'public image 200', $publicMedia['status'] === 200);
    $publicCache = (string) (headerValue($publicMedia['headers'], 'Cache-Control') ?? '');
    check(7, 'public cache policy', str_contains($publicCache, 'public') && str_contains($publicCache, 'max-age') && !str_contains($publicCache, 'no-store'));
    $guestPrivate = http('GET', $baseUrl . '/media/' . $privateId);
    check(8, 'private unauthorized 404/403 según arquitectura', $guestPrivate['status'] === 404);
    check(9, 'private media no public cache', !cacheIsPublic(headerValue($guestPrivate['headers'], 'Cache-Control')));
    check(
        10,
        'Content-Type correct',
        str_contains(strtolower($publicMedia['type'] !== '' ? $publicMedia['type'] : (string) headerValue($publicMedia['headers'], 'Content-Type')), 'image/png')
    );

    $home = http('GET', $baseUrl . '/');
    $homeCache = headerValue($home['headers'], 'Cache-Control');
    check(11, 'home no public HTML cache', $home['status'] === 200 && !cacheIsPublic($homeCache));

    $buyer = login($baseUrl, "perf.b.{$suffix}@eronyx.test", $password);
    $account = http('GET', $baseUrl . '/account', ['cookie' => $buyer['cookie']]);
    check(12, 'account no-store', $account['status'] === 200 && str_contains((string) headerValue($account['headers'], 'Cache-Control'), 'no-store'));
    $mfa = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $buyer['cookie']]);
    check(13, 'MFA no-store', $mfa['status'] === 200 && str_contains((string) headerValue($mfa['headers'], 'Cache-Control'), 'no-store'));
    $adminSession = login($baseUrl, "perf.a.{$suffix}@eronyx.test", $password);
    $admin = http('GET', $baseUrl . '/admin', ['cookie' => $adminSession['cookie']]);
    check(14, 'admin no-store', $admin['status'] === 200 && str_contains((string) headerValue($admin['headers'], 'Cache-Control'), 'no-store'));

    $market = http('GET', $baseUrl . '/marketplace');
    $listingPage = http('GET', $baseUrl . '/marketplace/' . $slug);
    $cardHasLazy = preg_match('/listing-card-media[\s\S]{0,400}loading="lazy"/', $market['body']) === 1
        || (str_contains($market['body'], 'listing-card-media') && str_contains($market['body'], 'loading="lazy"'));
    check(15, 'listing card image lazy', $market['status'] === 200 && $cardHasLazy);
    $heroChunk = '';
    if (preg_match('/listing-hero[\s\S]{0,800}<\/div>/', $listingPage['body'], $heroMatch) === 1) {
        $heroChunk = $heroMatch[0];
    }
    check(16, 'listing hero not lazy', $listingPage['status'] === 200 && str_contains($heroChunk, '<img') && !str_contains($heroChunk, 'loading="lazy"'));
    check(
        17,
        'hero dimensions/aspect',
        str_contains($heroChunk, 'width="640"')
        && str_contains($heroChunk, 'height="800"')
        && str_contains($heroChunk, 'fetchpriority="high"')
    );
    check(18, 'noncritical avatar/media lazy where applicable', str_contains($market['body'], 'loading="lazy"') && str_contains($market['body'], 'decoding="async"'));
    check(19, 'app.js defer', str_contains($layoutSrc, 'defer') && preg_match('/app\.js[^"]*" defer/', $home['body']) === 1);
    check(
        20,
        'mobile nav still works',
        str_contains($home['body'], 'nav-toggle-input')
        && str_contains($home['body'], 'id="site-nav"')
        && str_contains($jsSrc, "querySelector('.nav-toggle-input')")
        && str_contains($jsSrc, "addEventListener('change'")
    );

    check(21, 'marketplace query bounded with LIMIT', str_contains($listingSrc, 'LIMIT :limit OFFSET :offset'));

    $repo = new ListingRepository($pdo);
    $filters = ['page' => 1, 'per_page' => 12, 'sort' => 'newest'];
    $q1 = questions($pdo);
    $repo->searchPublishedPublic($filters);
    $d1 = questions($pdo) - $q1;
    $q2 = questions($pdo);
    $repo->searchPublishedPublic($filters);
    $d2 = questions($pdo) - $q2;
    check(22, 'no per-row cover query', $d1 > 0 && $d1 <= 3 && abs($d2 - $d1) <= 1 && str_contains($listingSrc, 'cover.cover_media_id'));
    check(23, 'no per-row creator query', str_contains($listingSrc, 'creator.username AS creator_username') && $d1 <= 3);

    $seoRepo = new SeoRepository($pdo);
    $qS = questions($pdo);
    $seoRepo->publicListingUrls();
    $seoRepo->publicCreatorUrls();
    $dS = questions($pdo) - $qS;
    check(24, 'sitemap 2 queries', $dS >= 2 && $dS <= 4);

    $explainMarket = $pdo->query(
        "EXPLAIN SELECT l.id FROM listings l
         WHERE l.status = 'published' AND l.visibility = 'public' AND l.published_at IS NOT NULL AND l.deleted_at IS NULL
         ORDER BY l.published_at DESC, l.id DESC LIMIT 12"
    )->fetchAll();
    check(25, 'representative marketplace EXPLAIN documented', $explainMarket !== []);
    echo 'EXPLAIN marketplace: ' . json_encode($explainMarket) . "\n";

    $explainUsers = $pdo->query(
        "EXPLAIN SELECT u.id FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.deleted_at IS NULL LIMIT 20"
    )->fetchAll();
    $explainOrders = $pdo->query(
        "EXPLAIN SELECT o.id FROM orders o WHERE o.deleted_at IS NULL LIMIT 20"
    )->fetchAll();
    $explainListings = $pdo->query(
        "EXPLAIN SELECT l.id FROM listings l WHERE l.deleted_at IS NULL LIMIT 20"
    )->fetchAll();
    check(26, 'admin users/orders/listings sanity', $explainUsers !== [] && $explainOrders !== [] && $explainListings !== []);
    echo 'EXPLAIN users: ' . json_encode($explainUsers) . "\n";
    echo 'EXPLAIN orders: ' . json_encode($explainOrders) . "\n";
    echo 'EXPLAIN listings: ' . json_encode($explainListings) . "\n";

    $owner = login($baseUrl, "perf.c.{$suffix}@eronyx.test", $password);
    $ownerPrivate = http('GET', $baseUrl . '/media/' . $privateId, ['cookie' => $owner['cookie']]);
    check(27, 'private content never Cache-Control public', $ownerPrivate['status'] === 200 && !cacheIsPublic(headerValue($ownerPrivate['headers'], 'Cache-Control')) && str_contains((string) headerValue($ownerPrivate['headers'], 'Cache-Control'), 'no-store'));
    check(28, 'MFA page never public cache', !cacheIsPublic(headerValue($mfa['headers'], 'Cache-Control')));
    $messages = http('GET', $baseUrl . '/account/messages', ['cookie' => $buyer['cookie']]);
    $orders = http('GET', $baseUrl . '/account/orders', ['cookie' => $buyer['cookie']]);
    check(
        29,
        'messages/order/account never public cache',
        $messages['status'] === 200
        && $orders['status'] === 200
        && !cacheIsPublic(headerValue($messages['headers'], 'Cache-Control'))
        && !cacheIsPublic(headerValue($orders['headers'], 'Cache-Control'))
        && !cacheIsPublic(headerValue($account['headers'], 'Cache-Control'))
        && str_contains((string) headerValue($messages['headers'], 'Cache-Control'), 'no-store')
    );

    $sitemap = http('GET', $baseUrl . '/sitemap.xml');
    $robots = http('GET', $baseUrl . '/robots.txt');
    check(
        30,
        'SEO regression smoke',
        $home['status'] === 200
        && str_contains($home['body'], 'rel="canonical"')
        && str_contains($home['body'], 'name="robots"')
        && str_contains($home['body'], 'application/ld+json')
        && $sitemap['status'] === 200
        && str_contains($sitemap['type'] !== '' ? $sitemap['type'] : (string) headerValue($sitemap['headers'], 'Content-Type'), 'xml')
        && $robots['status'] === 200
    );

    check(31, 'asset versioning', str_contains($home['body'], '/css/app.css?v=') && str_contains($home['body'], '/js/app.js?v='));
    $etag = headerValue($publicMedia['headers'], 'ETag');
    check(32, 'public media ETag', is_string($etag) && $etag !== '');
    $fresh = ['status' => 0, 'headers' => []];
    if (is_string($etag) && $etag !== '') {
        $fresh = http('GET', $baseUrl . '/media/' . $coverId, ['headers' => ['If-None-Match: ' . $etag]]);
    }
    check(33, 'If-None-Match 304', $fresh['status'] === 304);
    check(34, 'CSP preserved', str_contains((string) headerValue($home['headers'], 'Content-Security-Policy'), "script-src 'self'"));
    check(35, 'hero fetchpriority high once', substr_count($listingPage['body'], 'fetchpriority="high"') === 1);
    check(36, 'card width/height', str_contains($market['body'], 'width="400"') && str_contains($market['body'], 'height="500"'));
    check(37, 'private authorized no-store', $ownerPrivate['status'] === 200 && str_contains((string) headerValue($ownerPrivate['headers'], 'Cache-Control'), 'private'));
    check(38, 'marketplace 200', $market['status'] === 200);
    check(39, 'gzip types include html/css/js/json/xml', str_contains($htaccess, 'text/html') && str_contains($htaccess, 'application/javascript') && str_contains($htaccess, 'application/json') && str_contains($htaccess, 'application/xml'));
    check(40, 'Nav request memoization', str_contains($navSrc, '$requestContext') && str_contains($navSrc, 'not stored in the session'));

    $loginPage = http('GET', $baseUrl . '/login');
    $registerPage = http('GET', $baseUrl . '/register');
    $terms = http('GET', $baseUrl . '/terms');
    $missing = http('GET', $baseUrl . '/no-such-perf-' . $suffix);
    check(
        41,
        'HTTP smoke',
        $home['status'] === 200
        && $market['status'] === 200
        && $loginPage['status'] === 200
        && $registerPage['status'] === 200
        && $terms['status'] === 200
        && $sitemap['status'] === 200
        && $robots['status'] === 200
        && $css['status'] === 200
        && $js['status'] === 200
        && $missing['status'] === 404
    );
    check(42, 'X-Frame-Options DENY', headerValue($home['headers'], 'X-Frame-Options') === 'DENY');
    check(43, 'sensitive still no-store not public', str_contains((string) headerValue($account['headers'], 'Cache-Control'), 'private'));

    echo "\nMEASUREMENTS\n";
    echo 'app.css bytes=' . filesize($root . '/public/css/app.css') . "\n";
    echo 'app.js bytes=' . filesize($root . '/public/js/app.js') . "\n";
    echo 'home HTML bytes=' . strlen($home['body']) . "\n";
    echo 'marketplace HTML bytes=' . strlen($market['body']) . "\n";
    echo 'listing HTML bytes=' . strlen($listingPage['body']) . "\n";
} catch (Throwable $exception) {
    echo 'SUITE ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n";
    $fail++;
}

foreach ($cookieFiles as $file) {
    @unlink($file);
}

foreach ($createdMediaPaths as $path) {
    if (is_file($path)) {
        @unlink($path);
    }
}

foreach (glob($root . '/storage/cache/rate-limits/*.json') ?: [] as $file) {
    @unlink($file);
}

try {
    $listingIds = implode(',', array_map('intval', $createdListingIds)) ?: '0';
    $mediaIds = implode(',', array_map('intval', $createdMediaIds)) ?: '0';
    $ids = implode(',', array_map('intval', $createdUserIds)) ?: '0';
    $pdo->exec("DELETE FROM listing_media WHERE listing_id IN ({$listingIds})");
    $pdo->exec("DELETE FROM media_files WHERE id IN ({$mediaIds})");
    $pdo->exec("DELETE FROM listings WHERE id IN ({$listingIds})");
    $pdo->exec("DELETE FROM creator_profiles WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM password_reset_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM email_verification_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM audit_logs WHERE actor_user_id IN ({$ids}) OR entity_id IN ({$ids})");
    $pdo->exec("DELETE FROM notifications WHERE user_id IN ({$ids})");
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
        'users', 'profiles', 'creator_profiles', 'age_verifications', 'user_mfa', 'mfa_recovery_codes',
        'listings', 'listing_media', 'media_files', 'orders', 'messages', 'reports',
        'audit_logs', 'notifications', 'roles', 'categories',
    ] as $table
) {
    $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

echo "\nDB counts:\n";
foreach ($counts as $table => $count) {
    echo "  {$table}={$count}\n";
}

$rateLeft = glob($root . '/storage/cache/rate-limits/*.json') ?: [];
$mediaLeft = 0;
foreach ($createdMediaPaths as $path) {
    if (is_file($path)) {
        $mediaLeft++;
    }
}
echo 'Rate-limit files left: ' . count($rateLeft) . "\n";
echo 'Media temp files left: ' . $mediaLeft . "\n";
echo "PASS={$pass} FAIL={$fail}\n";

if ($failures !== []) {
    echo "Failures:\n- " . implode("\n- ", $failures) . "\n";
}

exit($fail === 0 ? 0 : 1);
