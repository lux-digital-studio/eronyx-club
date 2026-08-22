<?php

declare(strict_types=1);

use App\Core\Response;
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
$suffix = 'e' . bin2hex(random_bytes(3));
$password = 'ErrorPage1x';
$createdUserIds = [];
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
    $path = tempnam(sys_get_temp_dir(), 'eronyx_err_');
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
        'INSERT INTO users (email, password_hash, status, email_verified_at) VALUES (:email, :password_hash, :status, CURRENT_TIMESTAMP)'
    );
    $statement->execute(['email' => $email, 'password_hash' => $hash, 'status' => 'active']);
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

function looksDesigned(string $body, string $heading): bool
{
    return str_contains($body, 'error-page')
        && str_contains($body, 'error-title')
        && str_contains($body, $heading)
        && str_contains($body, '<!doctype html>')
        && !str_starts_with(trim($body), '404 - Not Found')
        && !str_starts_with(trim($body), '403 - Forbidden');
}

function leaksInternals(string $body): bool
{
    $lower = strtolower($body);
    return str_contains($body, 'SQLSTATE')
        || str_contains($body, 'Stack trace')
        || str_contains($lower, 'unhandled exception')
        || str_contains($body, 'C:\\xampp1')
        || str_contains($body, '/app/Core/')
        || str_contains($body, 'PDOException');
}

try {
    $missing = http('GET', $baseUrl . '/ruta-inexistente-' . $suffix);
    check(1, 'Ruta inexistente → 404', $missing['status'] === 404);
    check(
        2,
        '404 es página DESIGN-2',
        looksDesigned($missing['body'], 'Página no encontrada')
        && str_contains($missing['body'], 'Marketplace')
        && !str_contains($missing['body'], 'ruta-inexistente-' . $suffix)
    );

    createUser($pdo, "by.{$suffix}@eronyx.test", "by{$suffix}", 'Buyer', $password, ['buyer']);
    $buyer = login($baseUrl, "by.{$suffix}@eronyx.test", $password);
    $adminForbidden = http('GET', $baseUrl . '/admin', ['cookie' => $buyer['cookie']]);
    check(3, 'Buyer en /admin → 403', $adminForbidden['status'] === 403);
    check(
        4,
        '403 mantiene status y página',
        $adminForbidden['status'] === 403
        && looksDesigned($adminForbidden['body'], 'Acceso denegado')
        && !str_contains(strtolower($adminForbidden['body']), 'role_missing')
        && !str_contains($adminForbidden['body'], 'admin required')
    );

    $loginCookie = cookiePath();
    $limited = ['status' => 0, 'headers' => [], 'body' => ''];
    for ($i = 0; $i < 7; $i++) {
        $form = http('GET', $baseUrl . '/login', ['cookie' => $loginCookie]);
        $limited = http('POST', $baseUrl . '/login', [
            'cookie' => $loginCookie,
            'fields' => [
                '_csrf' => csrfFrom($form['body']),
                'email' => "nope.{$suffix}@eronyx.test",
                'password' => 'WrongPass99x',
            ],
        ]);
        if ($limited['status'] === 429) {
            break;
        }
    }
    check(5, 'Rate limit → 429', $limited['status'] === 429 && looksDesigned($limited['body'], 'Demasiadas solicitudes'));
    check(
        6,
        '429 mantiene Retry-After',
        $limited['status'] === 429 && headerValue($limited['headers'], 'Retry-After') !== null
    );

    ob_start();
    $response = new Response();
    $response->serverError();
    $local500 = (string) ob_get_clean();
    $localStatus = http_response_code();
    check(7, '500 local status correcto', $localStatus === 500 && looksDesigned($local500, 'Error del servidor'));

    $previousEnv = getenv('APP_ENV');
    putenv('APP_ENV=production');
    ob_start();
    (new Response())->serverError();
    $prod500 = (string) ob_get_clean();
    if (is_string($previousEnv)) {
        putenv('APP_ENV=' . $previousEnv);
    } else {
        putenv('APP_ENV');
    }
    check(8, 'Production 500 no filtra exception/path', !leaksInternals($prod500) && looksDesigned($prod500, 'Error del servidor'));
    check(9, 'No SQLSTATE', !str_contains($local500, 'SQLSTATE') && !str_contains($prod500, 'SQLSTATE'));
    check(10, 'No stack trace production', !str_contains($prod500, 'Stack trace') && !str_contains($prod500, '#0 '));

    $home = http('GET', $baseUrl . '/');
    $market = http('GET', $baseUrl . '/marketplace');
    $loginPage = http('GET', $baseUrl . '/login');
    $register = http('GET', $baseUrl . '/register');
    $account = http('GET', $baseUrl . '/account', ['cookie' => $buyer['cookie']]);
    check(
        11,
        'Páginas núcleo intactas',
        $home['status'] === 200
        && $market['status'] === 200
        && $loginPage['status'] === 200
        && $register['status'] === 200
        && $account['status'] === 200
    );
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
    $pdo->exec("DELETE FROM notifications WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM audit_logs WHERE actor_user_id IN ({$ids})");
    $pdo->exec("DELETE FROM email_verification_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM password_reset_tokens WHERE user_id IN ({$ids})");
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
        'users', 'profiles', 'user_roles', 'creator_profiles', 'age_verifications', 'user_consents', 'roles', 'categories',
        'listings', 'listing_categories', 'media_files', 'listing_media', 'private_content_access',
        'orders', 'order_items', 'payments', 'favorites',
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
echo "PASS={$pass} FAIL={$fail}\n";
if ($failures !== []) {
    echo "Failures:\n- " . implode("\n- ", $failures) . "\n";
}
exit($fail === 0 ? 0 : 1);
