<?php

declare(strict_types=1);

use App\Core\Response;
use App\Services\SeoService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$app = require $root . '/config/app.php';
$seoConfig = require $root . '/config/seo.php';
$db = require $root . '/config/database.php';
$baseUrl = rtrim((string) $app['url'], '/');
$pass = 0;
$fail = 0;
$failures = [];
$suffix = 'z' . bin2hex(random_bytes(3));
$password = 'SeoPass10xx';
$createdUserIds = [];
$createdListingIds = [];
$createdMediaIds = [];
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
    $path = tempnam(sys_get_temp_dir(), 'eronyx_seo_');

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

function createListing(PDO $pdo, int $ownerId, string $title, string $slug, string $status = 'published', string $visibility = 'public', string $description = 'Descripción pública segura.'): int
{
    global $createdListingIds;
    $statement = $pdo->prepare(
        "INSERT INTO listings (
            owner_user_id, title, slug, description, listing_type, status, price, currency, visibility, published_at
         ) VALUES (
            :owner_user_id, :title, :slug, :description, 'digital_content', :status, '9.50', 'EUR', :visibility, :published_at
         )"
    );
    $statement->execute([
        'owner_user_id' => $ownerId,
        'title' => $title,
        'slug' => $slug,
        'description' => $description,
        'status' => $status,
        'visibility' => $visibility,
        'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    $createdListingIds[] = $id;

    return $id;
}

function metaContent(string $html, string $name): string
{
    if (preg_match('/<meta name="' . preg_quote($name, '/') . '" content="([^"]*)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    return '';
}

function ogContent(string $html, string $property): string
{
    if (preg_match('/<meta property="' . preg_quote($property, '/') . '" content="([^"]*)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    return '';
}

function canonical(string $html): string
{
    if (preg_match('/<link rel="canonical" href="([^"]*)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    return '';
}

function pageTitle(string $html): string
{
    if (preg_match('/<title>([^<]*)<\/title>/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    return '';
}

function jsonLd(string $html): ?array
{
    if (preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches) !== 1) {
        return null;
    }

    $decoded = json_decode($matches[1], true);

    return is_array($decoded) ? $decoded : null;
}

function prodSeo(array $app, array $seoConfig): SeoService
{
    $app['env'] = 'production';

    return new SeoService($app, $seoConfig);
}

try {
    $prod = prodSeo($app, $seoConfig);
    $creatorId = createUser($pdo, "seo.c.{$suffix}@eronyx.test", "seoc{$suffix}", 'SEO Creator', $password, ['buyer', 'creator']);
    $buyerId = createUser($pdo, "seo.b.{$suffix}@eronyx.test", "seob{$suffix}", 'SEO Buyer', $password, ['buyer']);
    $adminId = createUser($pdo, "seo.a.{$suffix}@eronyx.test", "seoa{$suffix}", 'SEO Admin', $password, ['buyer', 'admin']);
    $modId = createUser($pdo, "seo.m.{$suffix}@eronyx.test", "seom{$suffix}", 'SEO Mod', $password, ['buyer', 'moderator']);
    $pdo->prepare("INSERT INTO creator_profiles (user_id, status) VALUES (:id, 'active')")->execute(['id' => $creatorId]);

    $pubSlug = "seo-pub-{$suffix}";
    $xssSlug = "seo-xss-{$suffix}";
    $draftSlug = "seo-draft-{$suffix}";
    $listingPub = createListing($pdo, $creatorId, 'SEO Listing ' . $suffix, $pubSlug);
    $listingXss = createListing(
        $pdo,
        $creatorId,
        '</title><script>alert(1)</script>',
        $xssSlug,
        'published',
        'public',
        '" onclick="alert(1)" desc </script><script>alert(2)</script>'
    );
    $listingDraft = createListing($pdo, $creatorId, 'Draft SEO', $draftSlug, 'draft', 'private');

    $privateKey = 'seo-private-' . $suffix . '-secretpath';
    $pdo->prepare(
        "INSERT INTO media_files (owner_user_id, storage_disk, storage_key, media_type, visibility, mime_type, size_bytes, checksum, status)
         VALUES (:owner, 'local', :storage_key, 'image', 'private', 'image/png', 12, :checksum, 'active')"
    )->execute(['owner' => $creatorId, 'storage_key' => $privateKey, 'checksum' => hash('sha256', $privateKey)]);
    $privateMediaId = (int) $pdo->lastInsertId();
    $createdMediaIds[] = $privateMediaId;
    $pdo->prepare("INSERT INTO listing_media (listing_id, media_file_id, usage_type, sort_order) VALUES (:l, :m, 'private_content', 1)")
        ->execute(['l' => $listingPub, 'm' => $privateMediaId]);

    $coverStmt = $pdo->prepare(
        "INSERT INTO media_files (owner_user_id, storage_disk, storage_key, media_type, visibility, mime_type, size_bytes, checksum, status)
         VALUES (:owner, 'local', :storage_key, 'image', 'public', 'image/png', 12, :checksum, 'active')"
    );
    $coverKey = 'seo-cover-' . $suffix;
    $coverStmt->execute(['owner' => $creatorId, 'storage_key' => $coverKey, 'checksum' => hash('sha256', $coverKey)]);
    $coverId = (int) $pdo->lastInsertId();
    $createdMediaIds[] = $coverId;
    $pdo->prepare("INSERT INTO listing_media (listing_id, media_file_id, usage_type, sort_order) VALUES (:l, :m, 'cover', 0)")
        ->execute(['l' => $listingPub, 'm' => $coverId]);

    $home = http('GET', $baseUrl . '/');
    check(1, 'home 200', $home['status'] === 200);
    check(2, 'title correcto', str_contains(pageTitle($home['body']), 'ERONYX'));
    check(3, 'description', metaContent($home['body'], 'description') !== '');
    check(4, 'canonical absoluto', str_starts_with(canonical($home['body']), $baseUrl));
    check(5, 'robots según environment', metaContent($home['body'], 'robots') === 'noindex, nofollow');
    check(6, 'OG', ogContent($home['body'], 'og:site_name') === 'ERONYX' && ogContent($home['body'], 'og:url') === canonical($home['body']));
    check(7, 'Twitter', metaContent($home['body'], 'twitter:card') !== '' && metaContent($home['body'], 'twitter:title') !== '');
    $homeLd = jsonLd($home['body']);
    check(8, 'JSON-LD parseable', is_array($homeLd) && ($homeLd['@type'] ?? '') === 'WebSite');

    $market = http('GET', $baseUrl . '/marketplace');
    check(9, 'marketplace 200', $market['status'] === 200);
    check(10, 'canonical marketplace', canonical($market['body']) === $baseUrl . '/marketplace');
    $search = http('GET', $baseUrl . '/marketplace?q=secretquery&utm_source=x');
    check(11, 'filtro no indexable', $prod->robotsFor('/marketplace', ['q' => 'secretquery']) === SeoService::ROBOTS_NOINDEX_FOLLOW && str_contains(metaContent($search['body'], 'robots'), 'noindex'));
    check(12, 'no private data', !str_contains($market['body'], $privateKey) && !str_contains($search['body'], $privateKey));

    $listingPage = http('GET', $baseUrl . '/marketplace/' . $pubSlug);
    check(13, 'published listing indexable', $listingPage['status'] === 200 && $prod->robotsFor('/marketplace/' . $pubSlug) === SeoService::ROBOTS_INDEX);
    check(14, 'unique title', str_contains(pageTitle($listingPage['body']), 'SEO Listing'));
    check(15, 'description escaped', str_contains($listingPage['body'], 'Descripción pública segura.') && !str_contains($listingPage['body'], $privateKey));
    check(16, 'canonical listing', canonical($listingPage['body']) === $baseUrl . '/marketplace/' . $pubSlug);
    check(17, 'public cover OG', ogContent($listingPage['body'], 'og:image') === $baseUrl . '/media/' . $coverId);
    $listingLd = jsonLd($listingPage['body']);
    check(18, 'JSON-LD listing', is_array($listingLd) && ($listingLd['@type'] ?? '') === 'Product' && !isset($listingLd['aggregateRating']));
    check(19, 'private media no metadata', !str_contains($listingPage['body'], $privateKey) && !str_contains((string) json_encode($listingLd), $privateKey));
    $draftPage = http('GET', $baseUrl . '/marketplace/' . $draftSlug);
    check(20, 'draft not public', $draftPage['status'] === 404);

    $creatorPage = http('GET', $baseUrl . '/creator/seoc' . $suffix);
    check(21, 'active creator metadata', $creatorPage['status'] === 200 && str_contains(pageTitle($creatorPage['body']), 'SEO Creator'));
    check(22, 'canonical creator', canonical($creatorPage['body']) === $baseUrl . '/creator/seoc' . $suffix);
    check(
        23,
        'no email/KYC/MFA',
        !str_contains($creatorPage['body'], "seo.c.{$suffix}@eronyx.test")
        && !str_contains(strtolower($creatorPage['body']), 'kyc')
        && !str_contains($creatorPage['body'], 'secret_encrypted')
    );
    $creatorLd = jsonLd($creatorPage['body']);
    check(24, 'Person JSON-LD', is_array($creatorLd) && ($creatorLd['@type'] ?? '') === 'Person' && !isset($creatorLd['email']));

    $loginPage = http('GET', $baseUrl . '/login');
    $registerPage = http('GET', $baseUrl . '/register');
    $buyer = login($baseUrl, "seo.b.{$suffix}@eronyx.test", $password);
    $account = http('GET', $baseUrl . '/account', ['cookie' => $buyer['cookie']]);
    $messages = http('GET', $baseUrl . '/account/messages', ['cookie' => $buyer['cookie']]);
    $orders = http('GET', $baseUrl . '/account/orders', ['cookie' => $buyer['cookie']]);
    $mfa = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $buyer['cookie']]);
    $adminSession = login($baseUrl, "seo.a.{$suffix}@eronyx.test", $password);
    $adminPage = http('GET', $baseUrl . '/admin', ['cookie' => $adminSession['cookie']]);
    $modSession = login($baseUrl, "seo.m.{$suffix}@eronyx.test", $password);
    $modPage = http('GET', $baseUrl . '/moderator', ['cookie' => $modSession['cookie']]);
    check(25, 'login noindex', str_contains(metaContent($loginPage['body'], 'robots'), 'noindex'));
    check(26, 'register noindex', str_contains(metaContent($registerPage['body'], 'robots'), 'noindex'));
    check(27, 'account noindex', $account['status'] === 200 && str_contains(metaContent($account['body'], 'robots'), 'noindex'));
    check(28, 'messages noindex', $messages['status'] === 200 && str_contains(metaContent($messages['body'], 'robots'), 'noindex'));
    check(29, 'orders noindex', $orders['status'] === 200 && str_contains(metaContent($orders['body'], 'robots'), 'noindex'));
    check(30, 'MFA noindex', $mfa['status'] === 200 && str_contains(metaContent($mfa['body'], 'robots'), 'noindex'));
    check(31, 'admin noindex', $adminPage['status'] === 200 && str_contains(metaContent($adminPage['body'], 'robots'), 'noindex'));
    check(32, 'moderator noindex', $modPage['status'] === 200 && str_contains(metaContent($modPage['body'], 'robots'), 'noindex'));

    $notFound = http('GET', $baseUrl . '/no-such-seo-page-' . $suffix);
    check(33, '404 status + noindex', $notFound['status'] === 404 && str_contains(metaContent($notFound['body'], 'robots'), 'noindex') && canonical($notFound['body']) !== $baseUrl . '/' && canonical($notFound['body']) !== $baseUrl && !str_contains($notFound['body'], 'no-such-seo-page-' . $suffix) && !str_contains($notFound['body'], 'rel="canonical"'));
    $forbidden = http('GET', $baseUrl . '/admin', ['cookie' => $buyer['cookie']]);
    check(34, '403 noindex', $forbidden['status'] === 403 && str_contains(metaContent($forbidden['body'], 'robots'), 'noindex'));
    $rateCookie = cookiePath();
    $limited = ['status' => 0, 'headers' => [], 'body' => ''];
    for ($i = 0; $i < 8; $i++) {
        $form = http('GET', $baseUrl . '/login', ['cookie' => $rateCookie]);
        $limited = http('POST', $baseUrl . '/login', [
            'cookie' => $rateCookie,
            'fields' => ['_csrf' => csrfFrom($form['body']), 'email' => "nope.{$suffix}@eronyx.test", 'password' => 'WrongPass99x'],
        ]);
        if ($limited['status'] === 429) {
            break;
        }
    }
    check(35, '429 noindex', $limited['status'] === 429 && str_contains(metaContent($limited['body'], 'robots'), 'noindex'));
    ob_start();
    (new Response())->serverError();
    $local500 = (string) ob_get_clean();
    check(36, '500 noindex without leak', str_contains($local500, 'noindex') && !str_contains($local500, 'SQLSTATE') && !str_contains($local500, 'Stack trace'));

    $sitemap = http('GET', $baseUrl . '/sitemap.xml');
    check(37, 'sitemap 200', $sitemap['status'] === 200);
    check(38, 'sitemap content-type', str_contains(strtolower($sitemap['type'] !== '' ? $sitemap['type'] : (headerValue($sitemap['headers'], 'Content-Type') ?? '')), 'xml'));
    $xml = @simplexml_load_string($sitemap['body']);
    check(39, 'valid XML', $xml !== false);
    $locs = [];
    if ($xml !== false) {
        foreach ($xml->url as $urlNode) {
            $locs[] = (string) $urlNode->loc;
        }
    }
    check(40, 'home included', in_array($baseUrl . '/', $locs, true) || in_array($baseUrl, $locs, true));
    check(41, 'marketplace included', in_array($baseUrl . '/marketplace', $locs, true));
    check(42, 'legal included', in_array($baseUrl . '/terms', $locs, true) && in_array($baseUrl . '/privacy', $locs, true));
    check(43, 'published listing included', in_array($baseUrl . '/marketplace/' . $pubSlug, $locs, true));
    check(44, 'active creator included', in_array($baseUrl . '/creator/seoc' . $suffix, $locs, true));
    check(45, 'draft excluded', !in_array($baseUrl . '/marketplace/' . $draftSlug, $locs, true));
    check(
        46,
        'private routes excluded',
        !in_array($baseUrl . '/login', $locs, true)
        && !in_array($baseUrl . '/account', $locs, true)
        && !in_array($baseUrl . '/admin', $locs, true)
        && !in_array($baseUrl . '/mfa/challenge', $locs, true)
    );
    check(47, 'private media excluded', !str_contains($sitemap['body'], $privateKey) && !str_contains($sitemap['body'], '/storage/'));

    $robots = http('GET', $baseUrl . '/robots.txt');
    check(48, 'robots 200', $robots['status'] === 200);
    check(49, 'text/plain', str_contains(strtolower($robots['type'] !== '' ? $robots['type'] : (headerValue($robots['headers'], 'Content-Type') ?? '')), 'text/plain'));
    $prodRobots = prodSeo($app, $seoConfig)->robotsTxt();
    check(50, 'production robots', str_contains($prodRobots, 'Sitemap: ' . $baseUrl . '/sitemap.xml') && str_contains($prodRobots, 'Disallow: /account/'));
    check(51, 'local/test disallow all', str_contains($robots['body'], 'Disallow: /'));
    check(52, 'sitemap reference where applicable', str_contains($prodRobots, 'Sitemap: '));

    $xssPage = http('GET', $baseUrl . '/marketplace/' . $xssSlug);
    check(53, 'HTML title cannot break title', substr_count($xssPage['body'], '<title>') === 1 && !str_contains($xssPage['body'], '<script>alert(1)</script>'));
    check(54, 'description cannot inject attribute', !str_contains($xssPage['body'], 'onclick="alert(1)"'));
    $xssLd = jsonLd($xssPage['body']);
    check(55, 'JSON-LD cannot break script', is_array($xssLd) && !str_contains($xssPage['body'], '</script><script>alert(2)'));

    $hostHome = http('GET', $baseUrl . '/', ['headers' => ['Host: evil.example']]);
    $hostCanonical = $hostHome['status'] === 200 ? canonical($hostHome['body']) : canonical($home['body']);
    check(56, 'Host header no canonical', str_starts_with($hostCanonical, $baseUrl) && !str_contains($hostCanonical, 'evil.example'));
    $hostMap = http('GET', $baseUrl . '/sitemap.xml', ['headers' => ['Host: evil.example']]);
    $hostMapBody = $hostMap['status'] === 200 ? $hostMap['body'] : $sitemap['body'];
    check(57, 'Host header no sitemap URLs', !str_contains($hostMapBody, 'evil.example') && str_contains($hostMapBody, $baseUrl));
    $hostOg = $hostHome['status'] === 200 ? ogContent($hostHome['body'], 'og:url') : ogContent($home['body'], 'og:url');
    check(58, 'Host header no OG URL', str_starts_with($hostOg, $baseUrl) && !str_contains($hostOg, 'evil.example'));

    $utm = http('GET', $baseUrl . '/marketplace?utm_source=ads&page=1');
    check(59, 'utm does not pollute canonical', canonical($utm['body']) === $baseUrl . '/marketplace');
    check(60, 'search robots policy', str_contains(metaContent($search['body'], 'robots'), 'noindex'));
    check(61, 'page=1 canonical normalized', canonical($utm['body']) === $baseUrl . '/marketplace');

    $local = new SeoService($app, $seoConfig);
    $testApp = $app;
    $testApp['env'] = 'test';
    $stagingApp = $app;
    $stagingApp['env'] = 'staging';
    check(62, 'local noindex', $local->robotsFor('/') === SeoService::ROBOTS_NOINDEX);
    check(63, 'test noindex', (new SeoService($testApp, $seoConfig))->robotsFor('/') === SeoService::ROBOTS_NOINDEX);
    check(64, 'production public indexable', $prod->robotsFor('/') === SeoService::ROBOTS_INDEX && $prod->robotsFor('/marketplace') === SeoService::ROBOTS_INDEX);
    check(65, 'production private remain noindex', $prod->robotsFor('/account') === SeoService::ROBOTS_NOINDEX && $prod->robotsFor('/admin') === SeoService::ROBOTS_NOINDEX && $prod->robotsFor('/mfa/challenge') === SeoService::ROBOTS_NOINDEX);

    check(66, 'staging noindex', (new SeoService($stagingApp, $seoConfig))->robotsFor('/') === SeoService::ROBOTS_NOINDEX);
    check(67, 'page 2 canonical keeps page', $prod->canonicalFor('/marketplace', ['page' => '2']) === $baseUrl . '/marketplace?page=2');
    check(68, 'filter canonical is base', $prod->canonicalFor('/marketplace', ['q' => 'x', 'page' => '2']) === $baseUrl . '/marketplace');
    check(69, 'single title/canonical', substr_count($home['body'], '<title>') === 1 && substr_count($home['body'], 'rel="canonical"') === 1);
    check(70, 'JSON-LD no rating invented', !str_contains($listingPage['body'], 'aggregateRating') && !str_contains($listingPage['body'], 'ratingValue'));

    $css = http('GET', $baseUrl . '/css/app.css');
    $js = http('GET', $baseUrl . '/js/app.js');
    $terms = http('GET', $baseUrl . '/terms');
    check(71, 'HTTP smoke', $home['status'] === 200 && $market['status'] === 200 && $loginPage['status'] === 200 && $registerPage['status'] === 200 && $terms['status'] === 200 && $sitemap['status'] === 200 && $robots['status'] === 200 && $notFound['status'] === 404 && $css['status'] === 200 && $js['status'] === 200);
    check(72, 'legal still draft', str_contains($terms['body'], 'Borrador') || str_contains($terms['body'], 'borrador'));
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
echo 'Rate-limit files left: ' . count($rateLeft) . "\n";
echo "PASS={$pass} FAIL={$fail}\n";

if ($failures !== []) {
    echo "Failures:\n- " . implode("\n- ", $failures) . "\n";
}

exit($fail === 0 ? 0 : 1);
