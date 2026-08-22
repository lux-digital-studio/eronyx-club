<?php

declare(strict_types=1);

use App\Core\Logger;
use App\Services\AccountSecurityService;
use App\Services\MfaCrypto;
use OTPHP\TOTP;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$app = require $root . '/config/app.php';
$db = require $root . '/config/database.php';
$baseUrl = rtrim((string) $app['url'], '/');
$pass = 0;
$fail = 0;
$failures = [];
$suffix = 'm' . bin2hex(random_bytes(3));
$password = 'MfaPass10xx';
$createdUserIds = [];
$cookieFiles = [];
$capturedSecrets = [];
$capturedCodes = [];

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
    $path = tempnam(sys_get_temp_dir(), 'eronyx_mfa_');

    if ($path === false) {
        throw new RuntimeException('tempnam failed');
    }

    $cookieFiles[] = $path;

    return $path;
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

function login(string $baseUrl, string $email, string $password): array
{
    global $root;
    clearRateLimits($root);
    $cookie = cookiePath();
    $page = http('GET', $baseUrl . '/login', ['cookie' => $cookie]);
    $post = http('POST', $baseUrl . '/login', [
        'cookie' => $cookie,
        'fields' => [
            '_csrf' => csrfFrom($page['body']),
            'email' => $email,
            'password' => $password,
        ],
    ]);

    return ['cookie' => $cookie, 'login' => $post, 'pre' => $page];
}

function roleId(PDO $pdo, string $name): int
{
    $statement = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
    $statement->execute(['name' => $name]);

    return (int) $statement->fetchColumn();
}

function createUser(PDO $pdo, string $email, string $username, string $display, string $password, array $roles, bool $verified = true): int
{
    global $createdUserIds;
    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($verified) {
        $statement = $pdo->prepare(
            "INSERT INTO users (email, password_hash, status, email_verified_at) VALUES (:email, :password_hash, 'active', CURRENT_TIMESTAMP)"
        );
    } else {
        $statement = $pdo->prepare(
            "INSERT INTO users (email, password_hash, status) VALUES (:email, :password_hash, 'active')"
        );
    }

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

function sessionVersion(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare('SELECT session_version FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $userId]);

    return (int) $statement->fetchColumn();
}

function mfaRow(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, user_id, type, status, secret_encrypted, enabled_at FROM user_mfa WHERE user_id = :id LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function currentTotp(PDO $pdo, int $userId): string
{
    $row = mfaRow($pdo, $userId);

    if ($row === null) {
        return '';
    }

    $secret = (new MfaCrypto())->decrypt((string) $row['secret_encrypted']);
    $totp = TOTP::createFromSecret($secret);
    $totp->setPeriod(30);
    $totp->setDigits(6);
    $totp->setDigest('sha1');

    return $totp->now();
}

function recoveryCodesFrom(string $html): array
{
    if (preg_match_all('/class="recovery-code">([^<]+)</', $html, $matches) === false) {
        return [];
    }

    return $matches[1] ?? [];
}

function recoveryHashes(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare('SELECT code_hash FROM mfa_recovery_codes WHERE user_id = :id');
    $statement->execute(['id' => $userId]);

    return array_map(static fn (array $row): string => (string) $row['code_hash'], $statement->fetchAll());
}

function ensureMfaKey(string $root): void
{
    $envPath = $root . '/.env';
    $contents = is_file($envPath) ? (string) file_get_contents($envPath) : '';

    if (preg_match('/^MFA_ENCRYPTION_KEY=(\S+)/m', $contents) === 1) {
        return;
    }

    $key = bin2hex(random_bytes(32));

    if (preg_match('/^MFA_ENCRYPTION_KEY=/m', $contents) === 1) {
        file_put_contents($envPath, (string) preg_replace('/^MFA_ENCRYPTION_KEY=.*$/m', 'MFA_ENCRYPTION_KEY=' . $key, $contents));

        return;
    }

    file_put_contents($envPath, rtrim($contents) . "\nMFA_ENCRYPTION_KEY=" . $key . "\n");
}

function startSetup(string $baseUrl, string $cookie): array
{
    $page = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $cookie]);

    return http('POST', $baseUrl . '/account/security/mfa/setup', [
        'cookie' => $cookie,
        'fields' => ['_csrf' => csrfFrom($page['body'])],
    ]);
}

function confirmSetup(string $baseUrl, string $cookie, string $code, ?string $csrf = null, array $extra = []): array
{
    if ($csrf === null) {
        $page = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $cookie]);
        $setup = http('POST', $baseUrl . '/account/security/mfa/setup', [
            'cookie' => $cookie,
            'fields' => ['_csrf' => csrfFrom($page['body'])],
        ]);
        $csrf = csrfFrom($setup['body']);
    }

    return http('POST', $baseUrl . '/account/security/mfa/confirm', [
        'cookie' => $cookie,
        'fields' => ['_csrf' => $csrf, 'code' => $code] + $extra,
    ]);
}

function logContents(string $root): string
{
    $out = '';

    foreach (glob($root . '/storage/logs/*.log') ?: [] as $file) {
        $out .= (string) file_get_contents($file);
    }

    return $out;
}

function clearRateLimits(string $root): void
{
    foreach (glob($root . '/storage/cache/rate-limits/*.json') ?: [] as $file) {
        @unlink($file);
    }
}

function locationHas(array $response, string $needle): bool
{
    return str_contains(headerValue($response['headers'], 'Location') ?? '', $needle);
}

ensureMfaKey($root);
clearRateLimits($root);

try {
    $php = PHP_BINARY;
    $migrateCmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/database/migrate.php');
    $out1 = [];
    $code1 = 0;
    exec($migrateCmd . ' 2>&1', $out1, $code1);
    $firstOut = implode("\n", $out1);
    $tables = $pdo->query("SHOW TABLES LIKE 'user_mfa'")->fetchAll();
    $codesTable = $pdo->query("SHOW TABLES LIKE 'mfa_recovery_codes'")->fetchAll();
    check(1, 'Migration applies', $code1 === 0 && $tables !== [] && $codesTable !== [], $firstOut);

    $out2 = [];
    $code2 = 0;
    exec($migrateCmd . ' 2>&1', $out2, $code2);
    $secondOut = implode("\n", $out2);
    check(
        2,
        'Second migrate idempotent',
        $code2 === 0
        && str_contains($secondOut, 'Migrations complete.')
        && !str_contains($secondOut, 'Migrated: 2026_08_22_000015_create_mfa_tables')
    );

    $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $fkMfa = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = " . $pdo->quote($schema) . "
           AND TABLE_NAME = 'user_mfa'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    )->fetchColumn();
    $fkCodes = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = " . $pdo->quote($schema) . "
           AND TABLE_NAME = 'mfa_recovery_codes'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    )->fetchColumn();
    $idxUser = $pdo->query("SHOW INDEX FROM user_mfa WHERE Key_name = 'user_mfa_user_id_unique'")->fetch();
    $idxCodes = $pdo->query("SHOW INDEX FROM mfa_recovery_codes WHERE Column_name = 'user_id'")->fetch();
    check(3, 'FK/index correct', $fkMfa >= 1 && $fkCodes >= 1 && is_array($idxUser) && is_array($idxCodes));

    $columns = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = " . $pdo->quote($schema) . " AND TABLE_NAME = 'user_mfa'"
    )->fetchAll(PDO::FETCH_COLUMN);
    check(
        4,
        'secret not plaintext schema',
        in_array('secret_encrypted', $columns, true) && !in_array('secret', $columns, true)
    );

    $emailMain = "mfa.a.{$suffix}@eronyx.test";
    $emailB = "mfa.b.{$suffix}@eronyx.test";
    $emailPlain = "mfa.p.{$suffix}@eronyx.test";
    $emailUnverified = "mfa.u.{$suffix}@eronyx.test";
    $emailAdmin = "mfa.ad.{$suffix}@eronyx.test";
    $emailRegen = "mfa.r.{$suffix}@eronyx.test";
    $emailReset = "mfa.z.{$suffix}@eronyx.test";

    $userMain = createUser($pdo, $emailMain, "mfaa{$suffix}", 'MFA A', $password, ['buyer']);
    $userB = createUser($pdo, $emailB, "mfab{$suffix}", 'MFA B', $password, ['buyer']);
    $userPlain = createUser($pdo, $emailPlain, "mfap{$suffix}", 'MFA P', $password, ['buyer']);
    $userUnverified = createUser($pdo, $emailUnverified, "mfau{$suffix}", 'MFA U', $password, ['buyer'], false);
    $userAdmin = createUser($pdo, $emailAdmin, "mfad{$suffix}", 'MFA Admin', $password, ['buyer', 'admin']);
    $userRegen = createUser($pdo, $emailRegen, "mfar{$suffix}", 'MFA R', $password, ['buyer']);
    $userReset = createUser($pdo, $emailReset, "mfaz{$suffix}", 'MFA Z', $password, ['buyer']);

    $guest = http('GET', $baseUrl . '/account/security/mfa');
    check(5, 'Guest setup -> auth redirect', $guest['status'] === 302 && locationHas($guest, '/login'));

    $unverifiedSession = login($baseUrl, $emailUnverified, $password);
    $unverifiedPage = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $unverifiedSession['cookie']]);
    check(6, 'Unverified user -> verify redirect', $unverifiedPage['status'] === 302 && locationHas($unverifiedPage, '/account/verify-email'));

    $authMain = login($baseUrl, $emailMain, $password);
    $mfaPage = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $authMain['cookie']]);
    check(7, 'Verified user setup page 200', $mfaPage['status'] === 200 && str_contains($mfaPage['body'], 'No activado'));

    $setup = http('POST', $baseUrl . '/account/security/mfa/setup', [
        'cookie' => $authMain['cookie'],
        'fields' => ['_csrf' => csrfFrom($mfaPage['body'])],
    ]);
    $pending = mfaRow($pdo, $userMain);
    $rawSecret = '';
    if (preg_match('/<code>([A-Z2-7]+)<\\/code>/', $setup['body'], $secretMatch) === 1) {
        $rawSecret = $secretMatch[1];
        $capturedSecrets[] = $rawSecret;
    }
    check(
        8,
        'Start setup creates pending secret encrypted',
        $setup['status'] === 200
        && is_array($pending)
        && $pending['status'] === 'pending'
        && is_string($pending['secret_encrypted'])
        && $pending['secret_encrypted'] !== ''
    );
    check(
        9,
        'Raw secret not DB',
        is_array($pending)
        && $rawSecret !== ''
        && $pending['secret_encrypted'] !== $rawSecret
        && !str_contains((string) $pending['secret_encrypted'], $rawSecret)
    );

    $wrongConfirm = http('POST', $baseUrl . '/account/security/mfa/confirm', [
        'cookie' => $authMain['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($setup['body']),
            'code' => '000000',
        ],
    ]);
    $stillPending = mfaRow($pdo, $userMain);
    check(
        10,
        'Wrong TOTP does not enable',
        $wrongConfirm['status'] === 200
        && is_array($stillPending)
        && $stillPending['status'] === 'pending'
        && str_contains($wrongConfirm['body'], 'Código no válido.')
    );

    $goodCode = currentTotp($pdo, $userMain);
    $goodConfirm = http('POST', $baseUrl . '/account/security/mfa/confirm', [
        'cookie' => $authMain['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($wrongConfirm['body'] !== '' ? $wrongConfirm['body'] : $setup['body']),
            'code' => $goodCode,
        ],
    ]);
    $enabledRow = mfaRow($pdo, $userMain);
    check(11, 'Correct TOTP enables', $goodConfirm['status'] === 302 && locationHas($goodConfirm, '/account/security/mfa/recovery') && is_array($enabledRow) && $enabledRow['status'] === 'enabled');

    $recoveryPage = http('GET', $baseUrl . '/account/security/mfa/recovery', ['cookie' => $authMain['cookie']]);
    $codes = recoveryCodesFrom($recoveryPage['body']);
    $capturedCodes = array_merge($capturedCodes, $codes);
    check(12, 'Exactly configured count', count($codes) === 10);
    check(13, 'Raw codes displayed once', $recoveryPage['status'] === 200 && str_contains($recoveryPage['body'], 'Guarda estos códigos en un lugar seguro.') && $codes !== []);

    $hashes = recoveryHashes($pdo, $userMain);
    $rawInDb = false;
    foreach ($codes as $code) {
        foreach ($hashes as $hash) {
            if ($hash === $code || str_contains($hash, str_replace('-', '', $code))) {
                $rawInDb = true;
            }
        }
    }
    $allHashed = $hashes !== [] && count($hashes) === 10;
    foreach ($hashes as $hash) {
        if (preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
            $allHashed = false;
        }
    }
    check(14, 'DB has hashes only', $allHashed && !$rawInDb);

    $reloadCodes = http('GET', $baseUrl . '/account/security/mfa/recovery', ['cookie' => $authMain['cookie']]);
    check(15, 'Reload no raw codes', recoveryCodesFrom($reloadCodes['body']) === [] && !str_contains($reloadCodes['body'], $codes[0] ?? 'XXXX-XXXX-XXXX'));

    $logoutPage = http('GET', $baseUrl . '/account', ['cookie' => $authMain['cookie']]);
    http('POST', $baseUrl . '/logout', [
        'cookie' => $authMain['cookie'],
        'fields' => ['_csrf' => csrfFrom($logoutPage['body'])],
    ]);

    $plainLogin = login($baseUrl, $emailPlain, $password);
    $plainAccount = http('GET', $baseUrl . '/account', ['cookie' => $plainLogin['cookie']]);
    check(18, 'MFA disabled → normal login', $plainLogin['login']['status'] === 302 && locationHas($plainLogin['login'], '/') && $plainAccount['status'] === 200);

    $mfaLogin = login($baseUrl, $emailMain, $password);
    $accountBeforeChallenge = http('GET', $baseUrl . '/account', ['cookie' => $mfaLogin['cookie']]);
    $challengePage = http('GET', $baseUrl . '/mfa/challenge', ['cookie' => $mfaLogin['cookie']]);
    check(
        19,
        'MFA enabled + correct password → challenge',
        $mfaLogin['login']['status'] === 302
        && locationHas($mfaLogin['login'], '/mfa/challenge')
        && $challengePage['status'] === 200
    );
    check(
        20,
        'No auth_user_id before challenge',
        $accountBeforeChallenge['status'] === 302 && locationHas($accountBeforeChallenge, '/login')
    );

    $wrongChallenge = http('POST', $baseUrl . '/mfa/challenge', [
        'cookie' => $mfaLogin['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($challengePage['body']),
            'method' => 'totp',
            'code' => '111111',
        ],
    ]);
    $stillGuest = http('GET', $baseUrl . '/account', ['cookie' => $mfaLogin['cookie']]);
    check(
        22,
        'Wrong TOTP → no login',
        $wrongChallenge['status'] === 200
        && str_contains($wrongChallenge['body'], 'Código no válido.')
        && $stillGuest['status'] === 302
        && locationHas($stillGuest, '/login')
    );

    $okChallenge = http('POST', $baseUrl . '/mfa/challenge', [
        'cookie' => $mfaLogin['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($wrongChallenge['body']),
            'method' => 'totp',
            'code' => currentTotp($pdo, $userMain),
        ],
    ]);
    $afterTotp = http('GET', $baseUrl . '/account', ['cookie' => $mfaLogin['cookie']]);
    check(21, 'Correct TOTP → login', $okChallenge['status'] === 302 && $afterTotp['status'] === 200);

    http('POST', $baseUrl . '/logout', [
        'cookie' => $mfaLogin['cookie'],
        'fields' => ['_csrf' => csrfFrom($afterTotp['body'])],
    ]);

    $recoveryLogin = login($baseUrl, $emailMain, $password);
    $recoveryChallenge = http('GET', $baseUrl . '/mfa/challenge', ['cookie' => $recoveryLogin['cookie']]);
    $usedCode = $codes[0] ?? '';
    $recoveryOk = http('POST', $baseUrl . '/mfa/challenge', [
        'cookie' => $recoveryLogin['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($recoveryChallenge['body']),
            'method' => 'recovery',
            'recovery_code' => $usedCode,
        ],
    ]);
    $afterRecovery = http('GET', $baseUrl . '/account', ['cookie' => $recoveryLogin['cookie']]);
    $usedAt = $pdo->prepare('SELECT used_at FROM mfa_recovery_codes WHERE user_id = :id AND used_at IS NOT NULL');
    $usedAt->execute(['id' => $userMain]);
    check(16, 'Code consume single-use', $recoveryOk['status'] === 302 && $afterRecovery['status'] === 200 && $usedAt->fetch() !== false);
    check(23, 'Recovery code → login', $recoveryOk['status'] === 302 && $afterRecovery['status'] === 200);

    http('POST', $baseUrl . '/logout', [
        'cookie' => $recoveryLogin['cookie'],
        'fields' => ['_csrf' => csrfFrom($afterRecovery['body'])],
    ]);

    $reuseLogin = login($baseUrl, $emailMain, $password);
    $reusePage = http('GET', $baseUrl . '/mfa/challenge', ['cookie' => $reuseLogin['cookie']]);
    $reuse = http('POST', $baseUrl . '/mfa/challenge', [
        'cookie' => $reuseLogin['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($reusePage['body']),
            'method' => 'recovery',
            'recovery_code' => $usedCode,
        ],
    ]);
    $reuseAccount = http('GET', $baseUrl . '/account', ['cookie' => $reuseLogin['cookie']]);
    check(17, 'Re-use fails', $reuse['status'] === 200 && str_contains($reuse['body'], 'Código no válido.') && $reuseAccount['status'] === 302);
    check(24, 'Used recovery code → reject', $reuse['status'] === 200 && $reuseAccount['status'] === 302);

    $sidLogin = login($baseUrl, $emailMain, $password);
    $sidBefore = sessionCookie($sidLogin['pre']['headers']);
    $sidPending = sessionCookie($sidLogin['login']['headers']);
    $sidChallenge = http('GET', $baseUrl . '/mfa/challenge', ['cookie' => $sidLogin['cookie']]);
    $sidComplete = http('POST', $baseUrl . '/mfa/challenge', [
        'cookie' => $sidLogin['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($sidChallenge['body']),
            'method' => 'totp',
            'code' => currentTotp($pdo, $userMain),
        ],
    ]);
    $sidAfter = sessionCookie($sidComplete['headers']);
    check(
        47,
        'Session ID changes during login/MFA',
        is_string($sidBefore) && is_string($sidPending) && is_string($sidAfter)
        && $sidBefore !== $sidPending
        && $sidPending !== $sidAfter
    );

    $sidAccount = http('GET', $baseUrl . '/account', ['cookie' => $sidLogin['cookie']]);
    http('POST', $baseUrl . '/logout', [
        'cookie' => $sidLogin['cookie'],
        'fields' => ['_csrf' => csrfFrom($sidAccount['body'])],
    ]);

    $failedLogin = login($baseUrl, $emailMain, $password);
    $failedPage = http('GET', $baseUrl . '/mfa/challenge', ['cookie' => $failedLogin['cookie']]);
    http('POST', $baseUrl . '/mfa/challenge', [
        'cookie' => $failedLogin['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($failedPage['body']),
            'code' => '222222',
        ],
    ]);
    $failedAccount = http('GET', $baseUrl . '/account', ['cookie' => $failedLogin['cookie']]);
    check(48, 'No authenticated state survives failed MFA', $failedAccount['status'] === 302 && locationHas($failedAccount, '/login'));

    $authB = login($baseUrl, $emailB, $password);
    $bPage = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $authB['cookie']]);
    $idorSetup = http('POST', $baseUrl . '/account/security/mfa/setup', [
        'cookie' => $authB['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($bPage['body']),
            'user_id' => (string) $userMain,
        ],
    ]);
    $bPending = mfaRow($pdo, $userB);
    $mainStill = mfaRow($pdo, $userMain);
    $idorConfirm = http('POST', $baseUrl . '/account/security/mfa/confirm', [
        'cookie' => $authB['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($idorSetup['body']),
            'code' => currentTotp($pdo, $userB),
            'user_id' => (string) $userMain,
        ],
    ]);
    $bAfter = mfaRow($pdo, $userB);
    check(
        49,
        'User A cannot change User B MFA',
        is_array($mainStill) && $mainStill['status'] === 'enabled' && is_array($bAfter) && (int) $bAfter['user_id'] === $userB
    );
    check(50, 'POST user_id ignored', $idorSetup['status'] === 200 && is_array($bPending) && (int) $bPending['user_id'] === $userB);

    $adminSession = login($baseUrl, $emailAdmin, $password);
    $adminDetail = http('GET', $baseUrl . '/admin/users/' . $userMain, ['cookie' => $adminSession['cookie']]);
    $adminBody = $adminDetail['body'];
    $adminLeak = false;
    foreach (array_merge($capturedSecrets, $capturedCodes, $hashes) as $sensitive) {
        if ($sensitive !== '' && str_contains($adminBody, (string) $sensitive)) {
            $adminLeak = true;
        }
    }
    check(
        44,
        'Admin detail no secret',
        $adminDetail['status'] === 200
        && str_contains($adminBody, 'Activado')
        && !str_contains($adminBody, 'secret_encrypted')
        && !str_contains(strtolower($adminBody), 'desactivar mfa')
        && !$adminLeak
    );

    $noStoreMfa = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $adminSession['cookie']]);
    $noStoreChallenge = http('GET', $baseUrl . '/mfa/challenge', ['cookie' => $failedLogin['cookie']]);
    check(
        46,
        'MFA pages no-store',
        str_contains(headerValue($noStoreMfa['headers'], 'Cache-Control') ?? '', 'no-store')
        && str_contains(headerValue($noStoreChallenge['headers'], 'Cache-Control') ?? '', 'no-store')
    );

    $setupQr = $setup['body'];
    check(
        45,
        'No external QR request',
        str_contains($setupQr, 'data:image/svg+xml')
        && !str_contains($setupQr, 'chart.googleapis.com')
        && !str_contains($setupQr, 'api.qrserver')
        && !str_contains($setupQr, 'chart.googleapis')
    );

    Logger::error('mfa_test_redact', [
        'totp' => $goodCode,
        'recovery_code' => $usedCode,
        'secret' => $rawSecret,
        'otpauth' => 'otpauth://totp/ERONYX:demo?secret=' . $rawSecret,
    ]);
    $logs = logContents($root);
    $logLeak = false;
    foreach (array_merge($capturedSecrets, $capturedCodes, [$goodCode, $usedCode]) as $sensitive) {
        if ($sensitive !== '' && str_contains($logs, (string) $sensitive)) {
            $logLeak = true;
        }
    }
    check(40, 'Raw TOTP secret not logs', $rawSecret === '' || !str_contains($logs, $rawSecret));
    check(41, 'Raw TOTP code not logs', $goodCode === '' || !str_contains($logs, $goodCode));
    check(42, 'Recovery raw not logs', $usedCode === '' || !str_contains($logs, $usedCode));

    $auditStmt = $pdo->prepare(
        "SELECT event_type, metadata_json FROM audit_logs WHERE actor_user_id = :id AND event_type LIKE 'mfa_%'"
    );
    $auditStmt->execute(['id' => $userMain]);
    $auditLeak = false;
    $auditEvents = [];
    foreach ($auditStmt->fetchAll() as $row) {
        $auditEvents[] = (string) $row['event_type'];
        $meta = (string) ($row['metadata_json'] ?? '');
        foreach (array_merge($capturedSecrets, $capturedCodes, [$goodCode, $usedCode, $rawSecret]) as $sensitive) {
            if ($sensitive !== '' && str_contains($meta, (string) $sensitive)) {
                $auditLeak = true;
            }
        }
    }
    check(
        43,
        'Audit no secret/code',
        !$auditLeak
        && in_array('mfa_setup_started', $auditEvents, true)
        && in_array('mfa_enabled', $auditEvents, true)
        && in_array('mfa_recovery_code_used', $auditEvents, true)
    );

    $authRegen = login($baseUrl, $emailRegen, $password);
    $regenPage = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $authRegen['cookie']]);
    $regenSetup = http('POST', $baseUrl . '/account/security/mfa/setup', [
        'cookie' => $authRegen['cookie'],
        'fields' => ['_csrf' => csrfFrom($regenPage['body'])],
    ]);
    $regenConfirm = http('POST', $baseUrl . '/account/security/mfa/confirm', [
        'cookie' => $authRegen['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($regenSetup['body']),
            'code' => currentTotp($pdo, $userRegen),
        ],
    ]);
    $regenCodesPage = http('GET', $baseUrl . '/account/security/mfa/recovery', ['cookie' => $authRegen['cookie']]);
    $oldRegenCodes = recoveryCodesFrom($regenCodesPage['body']);
    $oldHashes = recoveryHashes($pdo, $userRegen);
    $versionBeforeRegen = sessionVersion($pdo, $userRegen);

    $regenNoCsrf = http('POST', $baseUrl . '/account/security/mfa/recovery/regenerate', [
        'cookie' => $authRegen['cookie'],
        'fields' => [
            'current_password' => $password,
            'mfa_code' => currentTotp($pdo, $userRegen),
        ],
    ]);
    check(33, 'CSRF required', $regenNoCsrf['status'] === 403);

    $regenForm = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $authRegen['cookie']]);
    $regenBadAuth = http('POST', $baseUrl . '/account/security/mfa/recovery/regenerate', [
        'cookie' => $authRegen['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($regenForm['body']),
            'current_password' => 'WrongPass10x',
            'mfa_code' => currentTotp($pdo, $userRegen),
        ],
    ]);
    check(34, 'Auth confirmation required', $regenBadAuth['status'] === 302 && recoveryHashes($pdo, $userRegen) === $oldHashes);

    $regenForm2 = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $authRegen['cookie']]);
    $regenOk = http('POST', $baseUrl . '/account/security/mfa/recovery/regenerate', [
        'cookie' => $authRegen['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($regenForm2['body']),
            'current_password' => $password,
            'mfa_code' => currentTotp($pdo, $userRegen),
        ],
    ]);
    $newCodesPage = http('GET', $baseUrl . '/account/security/mfa/recovery', ['cookie' => $authRegen['cookie']]);
    $newCodes = recoveryCodesFrom($newCodesPage['body']);
    $newHashes = recoveryHashes($pdo, $userRegen);
    $capturedCodes = array_merge($capturedCodes, $newCodes);
    check(35, 'Old codes invalidated', $regenOk['status'] === 302 && $oldRegenCodes !== [] && !array_intersect($oldHashes, $newHashes));
    $newHashedOk = count($newHashes) === 10;
    foreach ($newHashes as $hash) {
        if (preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
            $newHashedOk = false;
        }
    }
    check(36, 'New codes hashed', $newHashedOk && !in_array($newCodes[0] ?? '', $newHashes, true));
    check(37, 'session_version increment', sessionVersion($pdo, $userRegen) === $versionBeforeRegen + 1);

    $authReset = login($baseUrl, $emailReset, $password);
    $resetPage = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $authReset['cookie']]);
    $resetSetup = http('POST', $baseUrl . '/account/security/mfa/setup', [
        'cookie' => $authReset['cookie'],
        'fields' => ['_csrf' => csrfFrom($resetPage['body'])],
    ]);
    http('POST', $baseUrl . '/account/security/mfa/confirm', [
        'cookie' => $authReset['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($resetSetup['body']),
            'code' => currentTotp($pdo, $userReset),
        ],
    ]);
    $resetSession = new \App\Core\Session();
    $security = new AccountSecurityService($resetSession, $pdo);
    $issued = $security->requestPasswordReset($emailReset, '127.0.0.1', true);
    $path = (string) parse_url((string) ($issued['reset_url'] ?? ''), PHP_URL_PATH);
    $parts = explode('/', trim($path, '/'));
    $resetRaw = (string) end($parts);
    $newPassword = 'MfaReset10x';
    $resetGuest = cookiePath();
    $resetGet = http('GET', $baseUrl . '/reset-password/' . $resetRaw, ['cookie' => $resetGuest]);
    http('POST', $baseUrl . '/reset-password/' . $resetRaw, [
        'cookie' => $resetGuest,
        'fields' => [
            '_csrf' => csrfFrom($resetGet['body']),
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ],
    ]);
    $resetMfa = mfaRow($pdo, $userReset);
    check(38, 'Reset password does NOT disable MFA', is_array($resetMfa) && $resetMfa['status'] === 'enabled');
    $afterResetLogin = login($baseUrl, $emailReset, $newPassword);
    check(39, 'Next login requires challenge', $afterResetLogin['login']['status'] === 302 && locationHas($afterResetLogin['login'], '/mfa/challenge'));

    $sessA = login($baseUrl, $emailMain, $password);
    $chalA = http('GET', $baseUrl . '/mfa/challenge', ['cookie' => $sessA['cookie']]);
    http('POST', $baseUrl . '/mfa/challenge', [
        'cookie' => $sessA['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($chalA['body']),
            'code' => currentTotp($pdo, $userMain),
        ],
    ]);
    $sessB = login($baseUrl, $emailMain, $password);
    $chalB = http('GET', $baseUrl . '/mfa/challenge', ['cookie' => $sessB['cookie']]);
    http('POST', $baseUrl . '/mfa/challenge', [
        'cookie' => $sessB['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($chalB['body']),
            'code' => currentTotp($pdo, $userMain),
        ],
    ]);

    $disableNoCsrf = http('POST', $baseUrl . '/account/security/mfa/disable', [
        'cookie' => $sessA['cookie'],
        'fields' => [
            'current_password' => $password,
            'mfa_code' => currentTotp($pdo, $userMain),
        ],
    ]);
    check(28, 'No CSRF -> 403', $disableNoCsrf['status'] === 403);

    $disableForm = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $sessA['cookie']]);
    $disableBadPw = http('POST', $baseUrl . '/account/security/mfa/disable', [
        'cookie' => $sessA['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($disableForm['body']),
            'current_password' => 'WrongPass10x',
            'mfa_code' => currentTotp($pdo, $userMain),
        ],
    ]);
    check(29, 'Wrong password -> fail', is_array(mfaRow($pdo, $userMain)) && (mfaRow($pdo, $userMain)['status'] ?? '') === 'enabled');

    $disableForm2 = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $sessA['cookie']]);
    $disableBadMfa = http('POST', $baseUrl . '/account/security/mfa/disable', [
        'cookie' => $sessA['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($disableForm2['body']),
            'current_password' => $password,
            'mfa_code' => '000000',
        ],
    ]);
    check(30, 'Wrong MFA -> fail', is_array(mfaRow($pdo, $userMain)) && (mfaRow($pdo, $userMain)['status'] ?? '') === 'enabled');

    $rateLogin = login($baseUrl, $emailMain, $password);
    $lastRate = null;
    for ($i = 0; $i < 10; $i++) {
        $ratePage = http('GET', $baseUrl . '/mfa/challenge', ['cookie' => $rateLogin['cookie']]);
        $lastRate = http('POST', $baseUrl . '/mfa/challenge', [
            'cookie' => $rateLogin['cookie'],
            'fields' => [
                '_csrf' => csrfFrom($ratePage['body'] !== '' ? $ratePage['body'] : ($lastRate['body'] ?? '')),
                'code' => '333333',
            ],
        ]);
    }
    $blocked = http('POST', $baseUrl . '/mfa/challenge', [
        'cookie' => $rateLogin['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($lastRate['body'] ?? ''),
            'code' => '333333',
        ],
    ]);
    check(25, 'Too many wrong TOTP → 429', $blocked['status'] === 429);
    $retryAfter = headerValue($blocked['headers'], 'Retry-After');
    check(26, 'Retry-After', $retryAfter !== null && (int) $retryAfter > 0);

    $stillBlocked = http('POST', $baseUrl . '/mfa/challenge', [
        'cookie' => $rateLogin['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($lastRate['body'] ?? ''),
            'code' => currentTotp($pdo, $userMain),
        ],
    ]);
    check(27, 'Correct code after active limit still blocked', $stillBlocked['status'] === 429);
    clearRateLimits($root);

    $disableForm3 = http('GET', $baseUrl . '/account/security/mfa', ['cookie' => $sessA['cookie']]);
    $disableOk = http('POST', $baseUrl . '/account/security/mfa/disable', [
        'cookie' => $sessA['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($disableForm3['body']),
            'current_password' => $password,
            'mfa_code' => currentTotp($pdo, $userMain),
        ],
    ]);
    $aStill = http('GET', $baseUrl . '/account', ['cookie' => $sessA['cookie']]);
    $bNext = http('GET', $baseUrl . '/account', ['cookie' => $sessB['cookie']]);
    check(31, 'Correct password + MFA -> disable', $disableOk['status'] === 302 && mfaRow($pdo, $userMain) === null);
    check(32, 'Other sessions invalidated', $aStill['status'] === 200 && $bNext['status'] === 302);
} catch (Throwable $exception) {
    echo 'SUITE ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n";
    $fail++;
}

foreach ($cookieFiles as $file) {
    @unlink($file);
}

clearRateLimits($root);

try {
    $ids = implode(',', array_map('intval', $createdUserIds)) ?: '0';
    $pdo->exec("DELETE FROM mfa_recovery_codes WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM user_mfa WHERE user_id IN ({$ids})");
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
        'users', 'user_mfa', 'mfa_recovery_codes', 'audit_logs', 'notifications',
        'profiles', 'user_roles', 'roles', 'categories', 'listings', 'orders',
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

$mailFiles = array_merge(
    glob($root . '/storage/mail/**') ?: [],
    glob($root . '/storage/mail/*') ?: []
);
echo 'Mail files: ' . count($mailFiles) . "\n";

$qrFiles = array_merge(
    glob($root . '/storage/**/*qr*') ?: [],
    glob($root . '/public/**/*qr*') ?: []
);
echo 'QR files: ' . count($qrFiles) . "\n";
echo "PASS={$pass} FAIL={$fail}\n";

if ($failures !== []) {
    echo "Failures:\n- " . implode("\n- ", $failures) . "\n";
}

exit($fail === 0 ? 0 : 1);
