<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\EnvironmentValidator;
use App\Core\Response;
use App\Services\MediaStorageService;
use App\Services\RateLimiter;
use App\Services\SeoService;
use App\Services\Verification\AgeVerificationProviderFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$app = require $root . '/config/app.php';
$seoConfig = require $root . '/config/seo.php';
$db = require $root . '/config/database.php';
$baseUrl = rtrim((string) $app['url'], '/');
$pass = 0;
$fail = 0;
$failures = [];
$cookieFiles = [];
$rateTemp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eronyx_preprod_rl_' . bin2hex(random_bytes(4));

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

function resultStatus(array $results, string $code): ?string
{
    foreach ($results as $result) {
        if ($result['code'] === $code) {
            return $result['status'];
        }
    }

    return null;
}

function validator(array $overrides = []): EnvironmentValidator
{
    global $root;

    return new EnvironmentValidator(
        array_merge([
            'name' => 'ERONYX',
            'env' => 'production',
            'debug' => false,
            'url' => 'https://eronyx.example',
        ], $overrides['app'] ?? []),
        array_merge([
            'mailer' => 'smtp',
            'host' => 'smtp.example',
            'from_address' => 'noreply@example.com',
        ], $overrides['mail'] ?? []),
        array_merge([
            'encryption_key' => str_repeat('ab', 32),
        ], $overrides['mfa'] ?? []),
        array_merge([
            'mode' => 'manual_review',
            'provider' => '',
        ], $overrides['verification'] ?? []),
        array_merge([
            'database' => 'eronyx',
            'username' => 'eronyx',
        ], $overrides['database'] ?? []),
        array_merge([
            'status' => 'published',
            'business_name' => 'Example SL',
            'legal_email' => 'legal@example.com',
            'privacy_email' => 'privacy@example.com',
            'jurisdiction' => 'Spain',
        ], $overrides['legal'] ?? []),
        $root
    );
}

function withAppEnv(string $env, callable $callback): mixed
{
    $original = getenv('APP_ENV');
    putenv('APP_ENV=' . $env);

    try {
        return $callback();
    } finally {
        if ($original === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $original);
        }
    }
}

try {
    $debugFail = validator(['app' => ['debug' => true]])->run('production');
    $appSrc = (string) file_get_contents($root . '/config/app.php');
    check(
        1,
        'production debug true rejected',
        resultStatus($debugFail, 'app_debug') === EnvironmentValidator::FAIL
        && str_contains($appSrc, "\$environment === 'production'")
        && str_contains($appSrc, '$debug = false')
    );

    $httpUrl = validator(['app' => ['url' => 'http://eronyx.example']])->run('production');
    check(2, 'production http APP_URL rejected', resultStatus($httpUrl, 'app_url') === EnvironmentValidator::FAIL);

    $arrayMail = validator(['mail' => ['mailer' => 'array']])->run('production');
    check(3, 'production mail=array rejected', resultStatus($arrayMail, 'mail_mailer') === EnvironmentValidator::FAIL);

    $missingKey = validator(['mfa' => ['encryption_key' => '']])->run('production');
    check(4, 'missing MFA key rejected', resultStatus($missingKey, 'mfa_key') === EnvironmentValidator::FAIL);

    $badKey = validator(['mfa' => ['encryption_key' => 'not-a-valid-mfa-key']])->run('production');
    check(5, 'malformed MFA key rejected', resultStatus($badKey, 'mfa_key') === EnvironmentValidator::FAIL);

    $goodKey = validator()->run('production');
    check(6, 'valid MFA key accepted', resultStatus($goodKey, 'mfa_key') === EnvironmentValidator::PASS && validator()->failed($goodKey) === false);

    $controller = new App\Controllers\OrderController();
    $isLocal = new ReflectionMethod($controller, 'isLocal');
    $isLocal->setAccessible(true);
    $localAllowsPay = $isLocal->invoke($controller) === true;
    $prodBlocksPay = withAppEnv('production', static function () use ($isLocal, $controller): bool {
        return $isLocal->invoke($controller) === false;
    });
    check(7, 'test-pay production blocked', $prodBlocksPay && EnvironmentValidator::allowsTestPayment('production') === false && EnvironmentValidator::allowsTestPayment('staging') === false);
    check(8, 'test-pay local works', $localAllowsPay && EnvironmentValidator::allowsTestPayment('local') === true);

    $forgot = http('GET', $baseUrl . '/forgot-password');
    check(
        9,
        'dev reset URL production absent',
        EnvironmentValidator::allowsDevResetUrl('production') === false
        && EnvironmentValidator::allowsDevResetUrl('staging') === false
        && !str_contains($forgot['body'], 'Enlace de desarrollo')
        && !str_contains($forgot['body'], 'dev-reset-url')
    );

    $testProviderBlocked = withAppEnv('production', static function (): bool {
        try {
            AgeVerificationProviderFactory::make(['mode' => 'provider', 'provider' => 'test']);
            return false;
        } catch (RuntimeException $exception) {
            return $exception->getMessage() === 'provider_not_configured';
        }
    });
    $testProviderLocal = AgeVerificationProviderFactory::make(['mode' => 'provider', 'provider' => 'test']);
    check(
        10,
        'test verification provider production safe',
        $testProviderBlocked
        && $testProviderLocal instanceof App\Services\Verification\TestAgeVerificationProvider
        && EnvironmentValidator::allowsTestVerificationProvider('production') === false
    );

    $envPublic = http('GET', $baseUrl . '/.env');
    $projectRootUrl = preg_replace('#/public$#', '', $baseUrl) ?: $baseUrl;
    $envRoot = http('GET', $projectRootUrl . '/.env');
    $envDenied = in_array($envPublic['status'], [403, 404], true)
        && in_array($envRoot['status'], [403, 404], true)
        && !str_contains($envPublic['body'], 'DB_PASSWORD=')
        && !str_contains($envRoot['body'], 'DB_PASSWORD=');
    check(11, '.env not web accessible', $envDenied);

    $storageHttp = http('GET', $projectRootUrl . '/storage/private/');
    $mediaHttp = http('GET', $baseUrl . '/storage/private/media/');
    check(
        12,
        'private storage not web accessible',
        in_array($storageHttp['status'], [403, 404], true)
        && in_array($mediaHttp['status'], [403, 404], true)
        && !str_contains($storageHttp['body'], 'media')
    );

    $loginPage = http('GET', $baseUrl . '/login');
    $account = http('GET', $baseUrl . '/account');
    $mfaChallenge = http('GET', $baseUrl . '/mfa/challenge');
    check(
        13,
        'sensitive routes no-store',
        str_contains((string) headerValue($loginPage['headers'], 'Cache-Control'), 'no-store')
        && in_array($account['status'], [302, 303], true)
        && str_contains((string) headerValue($account['headers'], 'Cache-Control'), 'no-store')
    );

    $home = http('GET', $baseUrl . '/');
    $csp = (string) headerValue($home['headers'], 'Content-Security-Policy');
    check(
        14,
        'CSP',
        str_contains($csp, "script-src 'self'")
        && str_contains($csp, "style-src 'self'")
        && str_contains($csp, "img-src 'self' data:")
        && !str_contains($csp, 'unsafe-eval')
    );

    $sessionSrc = (string) file_get_contents($root . '/app/Core/Session.php');
    check(
        15,
        'HTTPS cookie policy',
        str_contains($sessionSrc, "session.use_strict_mode', '1'")
        && str_contains($sessionSrc, "'httponly' => \$httponly")
        && str_contains($sessionSrc, "'samesite' => \$samesite")
        && str_contains($sessionSrc, "(\$app['env'] ?? 'local') === 'production'")
        && str_contains($sessionSrc, 'isHttps()')
    );

    $prodSeo = new SeoService(['env' => 'production', 'url' => 'https://eronyx.example', 'name' => 'ERONYX'], $seoConfig);
    check(
        16,
        'APP_URL controls URLs',
        $prodSeo->absolute('/marketplace') === 'https://eronyx.example/marketplace'
        && str_contains($prodSeo->robotsTxt(), 'Sitemap: https://eronyx.example/sitemap.xml')
    );

    $databaseSrc = (string) file_get_contents($root . '/app/Core/Database.php');
    $emulate = (new Database($db))->connection()->getAttribute(PDO::ATTR_EMULATE_PREPARES);
    check(17, 'DB emulate prepares false', $databaseSrc !== '' && str_contains($databaseSrc, 'ATTR_EMULATE_PREPARES => false') && (int) $emulate === 0);

    mkdir($rateTemp, 0755, true);
    $limiter = new RateLimiter($rateTemp);
    $limiter->hit('login:user@example.com:127.0.0.1', 60);
    $rateFiles = glob($rateTemp . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $rateName = $rateFiles !== [] ? basename($rateFiles[0]) : '';
    check(
        18,
        'rate files hashed',
        count($rateFiles) === 1
        && preg_match('/\A[a-f0-9]{64}\.json\z/', $rateName) === 1
        && !str_contains($rateName, 'example.com')
        && !str_contains($rateName, '127.0.0.1')
    );

    $mediaSrc = (string) file_get_contents($root . '/app/Services/MediaStorageService.php');
    check(
        19,
        'uploads MIME validation unchanged',
        str_contains($mediaSrc, 'FILEINFO_MIME_TYPE')
        && str_contains($mediaSrc, 'getimagesize')
        && !str_contains($mediaSrc, 'image/svg')
        && MediaStorageService::MAX_IMAGE_BYTES === 5 * 1024 * 1024
    );

    $secretKey = str_repeat('cd', 32);
    $preflightText = validator(['mfa' => ['encryption_key' => $secretKey]])->format(validator(['mfa' => ['encryption_key' => $secretKey]])->run('production'));
    $cli = [];
    $cliCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/preflight.php') . ' --local 2>&1', $cli, $cliCode);
    $cliOut = implode("\n", $cli);
    $secretValues = [];
    $envFile = is_file($root . '/.env') ? parse_ini_file($root . '/.env', false, INI_SCANNER_TYPED) : [];
    if (is_array($envFile)) {
        foreach (['MFA_ENCRYPTION_KEY', 'MAIL_PASSWORD', 'DB_PASSWORD'] as $envName) {
            $value = trim((string) ($envFile[$envName] ?? ''));
            if ($value !== '') {
                $secretValues[] = $value;
            }
        }
    }
    $dbPassword = trim((string) ($db['password'] ?? ''));
    if ($dbPassword !== '') {
        $secretValues[] = $dbPassword;
    }
    $secretValues[] = $secretKey;
    $leaked = false;
    foreach (array_unique($secretValues) as $secret) {
        if ($secret !== '' && (str_contains($cliOut, $secret) || str_contains($preflightText, $secret))) {
            $leaked = true;
            break;
        }
    }
    check(20, 'no secrets in preflight output', $leaked === false && $cliCode === 0 && str_contains($cliOut, 'PASS PHP >= 8.2'));

    $legalFail = validator(['legal' => [
        'status' => 'draft',
        'business_name' => '',
        'legal_email' => '',
        'privacy_email' => '',
        'jurisdiction' => '',
    ]])->run('production');
    $legalWarn = validator(['legal' => [
        'status' => 'draft',
        'business_name' => '',
        'legal_email' => '',
        'privacy_email' => '',
        'jurisdiction' => '',
    ]])->run('staging');
    check(
        21,
        'legal placeholders detected',
        resultStatus($legalFail, 'legal_placeholders') === EnvironmentValidator::FAIL
        && resultStatus($legalWarn, 'legal_placeholders') === EnvironmentValidator::WARN
    );

    check(
        22,
        'sitemap production HTTPS under prod config',
        str_starts_with($prodSeo->absolute('/sitemap.xml'), 'https://')
        && str_contains($prodSeo->robotsTxt(), 'Sitemap: https://eronyx.example/sitemap.xml')
    );

    $stagingSeo = new SeoService(['env' => 'staging', 'url' => 'https://staging.example', 'name' => 'ERONYX'], $seoConfig);
    check(
        23,
        'robots staging noindex',
        $stagingSeo->robotsFor('/') === SeoService::ROBOTS_NOINDEX
        && str_contains($stagingSeo->robotsTxt(), 'Disallow: /')
        && !str_contains($stagingSeo->robotsTxt(), 'Allow: /')
    );

    check(
        24,
        'MFA challenge works unchanged',
        in_array($mfaChallenge['status'], [200, 302, 303], true)
        && $mfaChallenge['status'] !== 500
        && !str_contains($mfaChallenge['body'], 'SQLSTATE')
    );

    $health = http('GET', $baseUrl . '/health');
    check(25, 'health endpoint absent or safe', $health['status'] === 404 && !str_contains($health['body'], 'DB_PASSWORD') && !str_contains($health['body'], 'APP_DEBUG'));

    $trace = http('TRACE', $baseUrl . '/');
    $htaccess = (string) file_get_contents($root . '/public/.htaccess');
    check(
        26,
        'TRACE blocked/configured',
        str_contains($htaccess, 'TRACE|TRACK')
        && (
            in_array($trace['status'], [403, 405, 501], true)
            || (
                !str_contains($trace['body'], 'ERONYX')
                && headerValue($trace['headers'], 'Content-Security-Policy') === null
                && !str_contains(strtolower($trace['type']), 'text/html')
            )
        )
    );

    $rootHt = (string) file_get_contents($root . '/.htaccess');
    $cssIndex = http('GET', $baseUrl . '/css/');
    check(
        27,
        'directory listing disabled where applicable',
        str_contains($htaccess, 'Options -Indexes')
        && str_contains($rootHt, 'Options -Indexes')
        && !str_contains($cssIndex['body'], 'Index of')
    );

    check(28, 'composer.lock exists', is_file($root . '/composer.lock'));
    check(29, 'PHP version compatible', PHP_VERSION_ID >= 80200);

    $extensions = ['pdo_mysql', 'openssl', 'mbstring', 'fileinfo', 'json', 'session', 'ctype', 'filter', 'hash'];
    $missing = [];
    foreach ($extensions as $extension) {
        if (!extension_loaded($extension)) {
            $missing[] = $extension;
        }
    }
    check(30, 'required extensions list', $missing === []);

    $market = http('GET', $baseUrl . '/marketplace');
    $registerPage = http('GET', $baseUrl . '/register');
    $admin = http('GET', $baseUrl . '/admin');
    $sitemap = http('GET', $baseUrl . '/sitemap.xml');
    $robots = http('GET', $baseUrl . '/robots.txt');
    $notFound = http('GET', $baseUrl . '/this-route-does-not-exist-' . bin2hex(random_bytes(3)));
    $css = http('GET', $baseUrl . '/css/app.css');
    $js = http('GET', $baseUrl . '/js/app.js');
    check(
        31,
        'HTTP smoke',
        $home['status'] === 200
        && $market['status'] === 200
        && $loginPage['status'] === 200
        && $registerPage['status'] === 200
        && in_array($account['status'], [302, 303], true)
        && in_array($admin['status'], [302, 303], true)
        && $sitemap['status'] === 200
        && $robots['status'] === 200
        && $notFound['status'] === 404
        && $css['status'] === 200
        && $js['status'] === 200
    );

    $localHttp = validator([
        'app' => ['url' => 'http://localhost/eronyx/public', 'env' => 'local', 'debug' => true],
        'mail' => ['mailer' => 'array'],
        'mfa' => ['encryption_key' => ''],
    ])->run('local');
    check(
        32,
        'local http APP_URL accepted',
        resultStatus($localHttp, 'app_url') === EnvironmentValidator::PASS
        && resultStatus($localHttp, 'mail_mailer') === EnvironmentValidator::PASS
        && validator([
            'app' => ['url' => 'http://localhost/eronyx/public', 'env' => 'local', 'debug' => true],
            'mail' => ['mailer' => 'array'],
            'mfa' => ['encryption_key' => ''],
        ])->failed($localHttp) === false
    );

    $example = (string) file_get_contents($root . '/.env.example');
    check(
        33,
        '.env.example placeholders',
        str_contains($example, 'SECURE_COOKIES=')
        && str_contains($example, 'COOKIE_SAMESITE=')
        && str_contains($example, 'TRUSTED_PROXIES=')
        && str_contains($example, 'MFA_ENCRYPTION_KEY=')
        && !preg_match('/MFA_ENCRYPTION_KEY=[A-Fa-f0-9]{64}/', $example)
    );

    $docs = (string) file_get_contents($root . '/docs/PRODUCTION_DEPLOYMENT.md');
    check(
        34,
        'deployment documentation present',
        str_contains($docs, '## Requirements')
        && str_contains($docs, 'php scripts/preflight.php --production')
        && str_contains($docs, 'composer install --no-dev')
        && !str_contains($docs, 'DB_PASSWORD=')
    );

    $response = new Response();
    $prodHeaders = $response->securityHeaderMap('/account', true, true, true);
    $localHeaders = $response->securityHeaderMap('/account', false, false, true);
    check(
        35,
        'HSTS production HTTPS only',
        isset($prodHeaders['Strict-Transport-Security'])
        && !isset($localHeaders['Strict-Transport-Security'])
        && ($prodHeaders['Strict-Transport-Security'] ?? '') === 'max-age=31536000; includeSubDomains'
    );
} catch (Throwable $exception) {
    echo 'SUITE ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n";
    $fail++;
}

foreach ($cookieFiles as $file) {
    @unlink($file);
}

foreach (glob($rateTemp . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
    @unlink($file);
}
if (is_dir($rateTemp)) {
    @rmdir($rateTemp);
}

foreach (glob($root . '/storage/cache/rate-limits/*.json') ?: [] as $file) {
    @unlink($file);
}

$counts = [];
foreach (
    [
        'users', 'profiles', 'creator_profiles', 'age_verifications', 'user_mfa', 'mfa_recovery_codes',
        'listings', 'media_files', 'orders', 'messages', 'reports',
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
