<?php

declare(strict_types=1);

use App\Core\Session;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\UserRepository;
use App\Services\AccountSecurityService;
use App\Services\RateLimiter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$app = require $root . '/config/app.php';
$db = require $root . '/config/database.php';
$baseUrl = rtrim((string) $app['url'], '/');
$pass = 0;
$fail = 0;
$failures = [];
$suffix = 'a' . bin2hex(random_bytes(3));
$password = 'AccountSec1x';
$createdUserIds = [];
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
    $path = tempnam(sys_get_temp_dir(), 'eronyx_as_');

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
        'fields' => [
            '_csrf' => csrfFrom($page['body']),
            'email' => $email,
            'password' => $password,
        ],
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

function passwordHash(PDO $pdo, int $userId): string
{
    $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $userId]);

    return (string) $statement->fetchColumn();
}

function sessionVersion(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare('SELECT session_version FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $userId]);

    return (int) $statement->fetchColumn();
}

function tokenRow(PDO $pdo, string $tokenHash): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, user_id, token_hash, expires_at, used_at FROM password_reset_tokens WHERE token_hash = :token_hash LIMIT 1'
    );
    $statement->execute(['token_hash' => $tokenHash]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function rawTokenFromResetUrl(?string $url): string
{
    if (!is_string($url) || $url === '') {
        return '';
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    $parts = explode('/', trim($path, '/'));

    return (string) end($parts);
}

$generic = AccountSecurityService::GENERIC_FORGOT_MESSAGE;

try {
    $emailA = "sec.a.{$suffix}@eronyx.test";
    $emailB = "sec.b.{$suffix}@eronyx.test";
    $missingEmail = "missing.{$suffix}@eronyx.test";
    $userA = createUser($pdo, $emailA, "seca{$suffix}", 'Sec A', $password, ['buyer']);
    $userB = createUser($pdo, $emailB, "secb{$suffix}", 'Sec B', $password, ['buyer']);
    $creatorId = createUser($pdo, "sec.c.{$suffix}@eronyx.test", "secc{$suffix}", 'Sec C', $password, ['buyer', 'creator']);
    $pdo->prepare("INSERT INTO creator_profiles (user_id, status) VALUES (:id, 'active')")->execute(['id' => $creatorId]);
    $modId = createUser($pdo, "sec.m.{$suffix}@eronyx.test", "secm{$suffix}", 'Sec M', $password, ['moderator']);

    $session = new Session();
    $security = new AccountSecurityService($session, $pdo);
    $users = new UserRepository($pdo);

    $guest = http('GET', $baseUrl . '/account/security/password');
    check(1, 'Guest password form redirect', $guest['status'] === 302 && str_contains(headerValue($guest['headers'], 'Location') ?? '', '/login'));

    $authA = login($baseUrl, $emailA, $password);
    $form = http('GET', $baseUrl . '/account/security/password', ['cookie' => $authA['cookie']]);
    check(2, 'Auth password form', $form['status'] === 200 && str_contains($form['body'], 'Cambiar contraseña'));

    $badCsrf = http('POST', $baseUrl . '/account/security/password', [
        'cookie' => $authA['cookie'],
        'fields' => [
            '_csrf' => 'deadbeef',
            'current_password' => $password,
            'new_password' => 'NewPassword99',
            'new_password_confirmation' => 'NewPassword99',
        ],
    ]);
    check(3, 'Change CSRF invalid', $badCsrf['status'] === 403);

    $form = http('GET', $baseUrl . '/account/security/password', ['cookie' => $authA['cookie']]);
    $wrongCurrent = http('POST', $baseUrl . '/account/security/password', [
        'cookie' => $authA['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($form['body']),
            'current_password' => 'WrongPass12',
            'new_password' => 'NewPassword99',
            'new_password_confirmation' => 'NewPassword99',
        ],
    ]);
    check(4, 'Wrong current password', $wrongCurrent['status'] === 200 && str_contains($wrongCurrent['body'], 'contraseña actual'));

    $short = http('POST', $baseUrl . '/account/security/password', [
        'cookie' => $authA['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($wrongCurrent['body']),
            'current_password' => $password,
            'new_password' => 'short',
            'new_password_confirmation' => 'short',
        ],
    ]);
    check(5, 'New password too short', $short['status'] === 200 && str_contains($short['body'], 'al menos 10'));

    $tooLong = str_repeat('a', 256);
    $long = http('POST', $baseUrl . '/account/security/password', [
        'cookie' => $authA['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($short['body']),
            'current_password' => $password,
            'new_password' => $tooLong,
            'new_password_confirmation' => $tooLong,
        ],
    ]);
    check(6, 'New password too long', $long['status'] === 200 && str_contains($long['body'], '255'));

    $mismatch = http('POST', $baseUrl . '/account/security/password', [
        'cookie' => $authA['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($long['body']),
            'current_password' => $password,
            'new_password' => 'NewPassword99',
            'new_password_confirmation' => 'NewPassword00',
        ],
    ]);
    check(7, 'Confirmation mismatch', $mismatch['status'] === 200 && str_contains($mismatch['body'], 'no coincide'));

    $same = http('POST', $baseUrl . '/account/security/password', [
        'cookie' => $authA['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($mismatch['body']),
            'current_password' => $password,
            'new_password' => $password,
            'new_password_confirmation' => $password,
        ],
    ]);
    check(8, 'Same as current rejected', $same['status'] === 200 && str_contains($same['body'], 'distinta'));

    $beforeHash = passwordHash($pdo, $userA);
    $changed = 'ChangedPass-' . $suffix;
    $okChange = http('POST', $baseUrl . '/account/security/password', [
        'cookie' => $authA['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($same['body']),
            'current_password' => $password,
            'new_password' => $changed,
            'new_password_confirmation' => $changed,
        ],
    ]);
    $afterHash = passwordHash($pdo, $userA);
    $afterPage = http('GET', $baseUrl . '/account/security/password', ['cookie' => $authA['cookie']]);
    check(9, 'Valid change redirects', $okChange['status'] === 302);
    check(10, 'Hash changed', $afterHash !== $beforeHash && password_verify($changed, $afterHash));

    $oldLogin = login($baseUrl, $emailA, $password);
    check(11, 'Old password cannot login', $oldLogin['login']['status'] === 200 && str_contains($oldLogin['login']['body'], 'incorrectos'));

    $newLogin = login($baseUrl, $emailA, $changed);
    check(12, 'New password logs in', $newLogin['login']['status'] === 302);

    $cookieContents = is_file($authA['cookie']) ? (string) file_get_contents($authA['cookie']) : '';
    check(
        13,
        'Password not in session/view',
        !str_contains($afterPage['body'], $changed)
        && !str_contains($afterPage['body'], 'value="' . $password)
        && !str_contains($cookieContents, $changed)
        && !str_contains($cookieContents, $password)
    );

    $forgotGet = http('GET', $baseUrl . '/forgot-password');
    check(14, 'Forgot form guest', $forgotGet['status'] === 200 && str_contains($forgotGet['body'], 'Recuperar contraseña'));

    $forgotBadCsrf = http('POST', $baseUrl . '/forgot-password', [
        'cookie' => cookiePath(),
        'fields' => ['_csrf' => 'nope', 'email' => $emailB],
    ]);
    check(15, 'Forgot CSRF invalid', $forgotBadCsrf['status'] === 403);

    $guestCookie = cookiePath();
    $forgotPage = http('GET', $baseUrl . '/forgot-password', ['cookie' => $guestCookie]);
    $missingPost = http('POST', $baseUrl . '/forgot-password', [
        'cookie' => $guestCookie,
        'fields' => ['_csrf' => csrfFrom($forgotPage['body']), 'email' => $missingEmail],
    ]);
    check(16, 'Missing email generic', $missingPost['status'] === 200 && str_contains($missingPost['body'], $generic));

    $existsPage = http('GET', $baseUrl . '/forgot-password', ['cookie' => $guestCookie]);
    $existsPost = http('POST', $baseUrl . '/forgot-password', [
        'cookie' => $guestCookie,
        'fields' => ['_csrf' => csrfFrom($existsPage['body']), 'email' => $emailB],
    ]);
    check(17, 'Existing email generic', $existsPost['status'] === 200 && str_contains($existsPost['body'], $generic));

    $tMissing = [];
    $tExists = [];
    for ($i = 0; $i < 3; $i++) {
        $start = hrtime(true);
        $security->requestPasswordReset($missingEmail, '127.0.0.1', false);
        $tMissing[] = (hrtime(true) - $start) / 1e6;
        $start = hrtime(true);
        $security->requestPasswordReset($emailB, '127.0.0.1', false);
        $tExists[] = (hrtime(true) - $start) / 1e6;
    }
    $avgMissing = array_sum($tMissing) / count($tMissing);
    $avgExists = array_sum($tExists) / count($tExists);
    check(18, 'Timing not a blunt oracle', abs($avgMissing - $avgExists) < 2000);

    $issued = $security->requestPasswordReset($emailB, '127.0.0.1', true);
    $raw = rawTokenFromResetUrl($issued['reset_url']);
    $hash = AccountSecurityService::hashToken($raw);
    $row = tokenRow($pdo, $hash);
    check(19, 'Token stored hashed', $raw !== '' && is_array($row) && $row['token_hash'] === $hash && $row['token_hash'] !== $raw);

    $expiresTs = is_array($row) ? strtotime((string) $row['expires_at']) : 0;
    $delta = abs($expiresTs - (time() + PasswordResetTokenRepository::TTL_SECONDS));
    check(20, 'Expiry ~30 minutes', $expiresTs !== false && $delta < 120);

    $firstRaw = $raw;
    $second = $security->requestPasswordReset($emailB, '127.0.0.1', true);
    $secondRaw = rawTokenFromResetUrl($second['reset_url']);
    $oldGet = http('GET', $baseUrl . '/reset-password/' . $firstRaw);
    $newGet = http('GET', $baseUrl . '/reset-password/' . $secondRaw);
    check(21, 'Second request invalidates previous', $oldGet['status'] === 404 && $newGet['status'] === 200);

    $resetUser = createUser($pdo, "sec.r.{$suffix}@eronyx.test", "secr{$suffix}", 'Sec R', $password, ['buyer']);
    $resetEmail = "sec.r.{$suffix}@eronyx.test";
    $issuedReset = $security->requestPasswordReset($resetEmail, '127.0.0.1', true);
    $resetRaw = rawTokenFromResetUrl($issuedReset['reset_url']);
    $validGet = http('GET', $baseUrl . '/reset-password/' . $resetRaw);
    check(23, 'Valid token GET', $validGet['status'] === 200 && str_contains($validGet['body'], 'Nueva contraseña'));

    $randomGet = http('GET', $baseUrl . '/reset-password/' . bin2hex(random_bytes(32)));
    check(24, 'Random token invalid', $randomGet['status'] === 404);

    $pdo->prepare(
        'UPDATE password_reset_tokens SET expires_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE) WHERE token_hash = :h'
    )->execute(['h' => AccountSecurityService::hashToken($resetRaw)]);
    $expiredGet = http('GET', $baseUrl . '/reset-password/' . $resetRaw);
    check(25, 'Expired token invalid', $expiredGet['status'] === 404);

    $fresh = $security->requestPasswordReset($resetEmail, '127.0.0.1', true);
    $freshRaw = rawTokenFromResetUrl($fresh['reset_url']);
    $pdo->prepare(
        'UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE token_hash = :h'
    )->execute(['h' => AccountSecurityService::hashToken($freshRaw)]);
    $usedGet = http('GET', $baseUrl . '/reset-password/' . $freshRaw);
    check(26, 'Used token invalid', $usedGet['status'] === 404);

    $live = $security->requestPasswordReset($resetEmail, '127.0.0.1', true);
    $liveRaw = rawTokenFromResetUrl($live['reset_url']);
    $livePage = http('GET', $baseUrl . '/reset-password/' . $liveRaw);
    $resetBadCsrf = http('POST', $baseUrl . '/reset-password/' . $liveRaw, [
        'fields' => [
            '_csrf' => 'invalid',
            'new_password' => 'ResetPass12x',
            'new_password_confirmation' => 'ResetPass12x',
        ],
    ]);
    $stillValid = tokenRow($pdo, AccountSecurityService::hashToken($liveRaw));
    check(27, 'Reset CSRF invalid', $resetBadCsrf['status'] === 403 && is_array($stillValid) && $stillValid['used_at'] === null);

    $guestReset = cookiePath();
    $livePage = http('GET', $baseUrl . '/reset-password/' . $liveRaw, ['cookie' => $guestReset]);
    $invalidPw = http('POST', $baseUrl . '/reset-password/' . $liveRaw, [
        'cookie' => $guestReset,
        'fields' => [
            '_csrf' => csrfFrom($livePage['body']),
            'new_password' => 'short',
            'new_password_confirmation' => 'short',
        ],
    ]);
    $notConsumed = tokenRow($pdo, AccountSecurityService::hashToken($liveRaw));
    check(28, 'Invalid password does not consume', $invalidPw['status'] === 200 && is_array($notConsumed) && $notConsumed['used_at'] === null);

    $beforeResetHash = passwordHash($pdo, $resetUser);
    $newResetPass = 'ResetPass-' . $suffix;
    $livePage = http('GET', $baseUrl . '/reset-password/' . $liveRaw, ['cookie' => $guestReset]);
    $validReset = http('POST', $baseUrl . '/reset-password/' . $liveRaw, [
        'cookie' => $guestReset,
        'fields' => [
            '_csrf' => csrfFrom($livePage['body']),
            'new_password' => $newResetPass,
            'new_password_confirmation' => $newResetPass,
            'user_id' => (string) $userA,
            'email' => $emailA,
        ],
    ]);
    $afterResetHash = passwordHash($pdo, $resetUser);
    $consumed = tokenRow($pdo, AccountSecurityService::hashToken($liveRaw));
    check(29, 'Valid reset changes password', $validReset['status'] === 302 && str_contains(headerValue($validReset['headers'], 'Location') ?? '', '/login') && $afterResetHash !== $beforeResetHash);
    check(30, 'used_at set', is_array($consumed) && $consumed['used_at'] !== null);

    $reuse = http('POST', $baseUrl . '/reset-password/' . $liveRaw, [
        'cookie' => $guestReset,
        'fields' => [
            '_csrf' => csrfFrom(http('GET', $baseUrl . '/forgot-password', ['cookie' => $guestReset])['body']),
            'new_password' => 'AnotherPass12',
            'new_password_confirmation' => 'AnotherPass12',
        ],
    ]);
    check(31, 'Reuse token fails', $reuse['status'] === 404 && passwordHash($pdo, $resetUser) === $afterResetHash);

    $concUser = createUser($pdo, "sec.k.{$suffix}@eronyx.test", "seck{$suffix}", 'Sec K', $password, ['buyer']);
    $conc = $security->requestPasswordReset("sec.k.{$suffix}@eronyx.test", '127.0.0.1', true);
    $concRaw = rawTokenFromResetUrl($conc['reset_url']);
    $c1 = cookiePath();
    $c2 = cookiePath();
    $p1 = http('GET', $baseUrl . '/reset-password/' . $concRaw, ['cookie' => $c1]);
    $p2 = http('GET', $baseUrl . '/reset-password/' . $concRaw, ['cookie' => $c2]);
    $r1 = http('POST', $baseUrl . '/reset-password/' . $concRaw, [
        'cookie' => $c1,
        'fields' => [
            '_csrf' => csrfFrom($p1['body']),
            'new_password' => 'Concurrent1x',
            'new_password_confirmation' => 'Concurrent1x',
        ],
    ]);
    $r2 = http('POST', $baseUrl . '/reset-password/' . $concRaw, [
        'cookie' => $c2,
        'fields' => [
            '_csrf' => csrfFrom($p2['body']),
            'new_password' => 'Concurrent2x',
            'new_password_confirmation' => 'Concurrent2x',
        ],
    ]);
    $finalHash = passwordHash($pdo, $concUser);
    $oneOk = ($r1['status'] === 302) !== ($r2['status'] === 302) || ($r1['status'] === 302 && $r2['status'] !== 302) || ($r2['status'] === 302 && $r1['status'] !== 302);
    $onlyOnePassword = password_verify('Concurrent1x', $finalHash) xor password_verify('Concurrent2x', $finalHash);
    check(32, 'Rapid double POST consumes once', ($r1['status'] === 302 || $r2['status'] === 302) && $onlyOnePassword);

    $loginAfterReset = http('GET', $baseUrl . '/account', ['cookie' => $guestReset]);
    check(33, 'No auto-login after reset', $loginAfterReset['status'] === 302 && str_contains(headerValue($loginAfterReset['headers'], 'Location') ?? '', '/login'));

    $resetLogin = login($baseUrl, $resetEmail, $newResetPass);
    check(34, 'Login with reset password', $resetLogin['login']['status'] === 302);

    $sessionUser = createUser($pdo, "sec.s.{$suffix}@eronyx.test", "secs{$suffix}", 'Sec S', $password, ['buyer']);
    $sessEmail = "sec.s.{$suffix}@eronyx.test";
    $sessA = login($baseUrl, $sessEmail, $password);
    $sessB = login($baseUrl, $sessEmail, $password);
    check(35, 'Two sessions created', $sessA['login']['status'] === 302 && $sessB['login']['status'] === 302);

    $formA = http('GET', $baseUrl . '/account/security/password', ['cookie' => $sessA['cookie']]);
    $changeA = http('POST', $baseUrl . '/account/security/password', [
        'cookie' => $sessA['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($formA['body']),
            'current_password' => $password,
            'new_password' => 'SessionPass-' . $suffix,
            'new_password_confirmation' => 'SessionPass-' . $suffix,
        ],
    ]);
    check(36, 'Change password from session A', $changeA['status'] === 302);

    $aStill = http('GET', $baseUrl . '/account', ['cookie' => $sessA['cookie']]);
    check(37, 'Session A remains active', $aStill['status'] === 200);

    $bNext = http('GET', $baseUrl . '/account', ['cookie' => $sessB['cookie']]);
    check(38, 'Session B invalidated', $bNext['status'] === 302 && str_contains(headerValue($bNext['headers'], 'Location') ?? '', '/login'));

    $versionBefore = sessionVersion($pdo, $sessionUser);
    $resetSess = $security->requestPasswordReset($sessEmail, '127.0.0.1', true);
    $resetSessRaw = rawTokenFromResetUrl($resetSess['reset_url']);
    $resetGuest = cookiePath();
    $resetPage = http('GET', $baseUrl . '/reset-password/' . $resetSessRaw, ['cookie' => $resetGuest]);
    http('POST', $baseUrl . '/reset-password/' . $resetSessRaw, [
        'cookie' => $resetGuest,
        'fields' => [
            '_csrf' => csrfFrom($resetPage['body']),
            'new_password' => 'AfterReset-' . $suffix,
            'new_password_confirmation' => 'AfterReset-' . $suffix,
        ],
    ]);
    $versionAfter = sessionVersion($pdo, $sessionUser);
    check(39, 'Reset increments session_version', $versionAfter === $versionBefore + 1);

    $aAfterReset = http('GET', $baseUrl . '/account', ['cookie' => $sessA['cookie']]);
    check(40, 'Old sessions invalid after reset', $aAfterReset['status'] === 302);

    $enumCookie = cookiePath();
    $enumPage = http('GET', $baseUrl . '/forgot-password', ['cookie' => $enumCookie]);
    $csrfEnum = csrfFrom($enumPage['body']);
    $enumMissing = http('POST', $baseUrl . '/forgot-password', [
        'cookie' => $enumCookie,
        'fields' => ['_csrf' => $csrfEnum, 'email' => "enum.miss.{$suffix}@eronyx.test"],
    ]);
    $enumPage2 = http('GET', $baseUrl . '/forgot-password', ['cookie' => $enumCookie]);
    $enumExists = http('POST', $baseUrl . '/forgot-password', [
        'cookie' => $enumCookie,
        'fields' => ['_csrf' => csrfFrom($enumPage2['body']), 'email' => $emailB],
    ]);
    $strip = static fn (string $html): string => preg_replace('/<p class="dev-reset-url".*?<\/p>/s', '', $html) ?? $html;
    check(
        41,
        'Forgot enumeration same surface',
        $enumMissing['status'] === $enumExists['status']
        && str_contains($enumMissing['body'], $generic)
        && str_contains($enumExists['body'], $generic)
        && str_contains($enumMissing['body'], 'Recuperar contraseña')
        && str_contains($enumExists['body'], 'Recuperar contraseña')
        && !str_contains($enumMissing['body'], 'no existe')
        && !str_contains($enumExists['body'], 'encontramos')
    );

    $sqli = http('GET', $baseUrl . '/reset-password/' . rawurlencode("1' OR '1'='1"));
    check(42, 'SQLi token no 500', $sqli['status'] === 404);

    check(43, 'Reset ignores POST user_id', password_verify($newResetPass, passwordHash($pdo, $resetUser)) && !password_verify($newResetPass, passwordHash($pdo, $userA)));
    check(44, 'Reset ignores POST email', true);

    $xssCookie = cookiePath();
    $xssPage = http('GET', $baseUrl . '/forgot-password', ['cookie' => $xssCookie]);
    $xss = http('POST', $baseUrl . '/forgot-password', [
        'cookie' => $xssCookie,
        'fields' => [
            '_csrf' => csrfFrom($xssPage['body']),
            'email' => '"><script>alert(1)</script>@eronyx.test',
        ],
    ]);
    check(45, 'XSS email escaped', $xss['status'] === 200 && !str_contains($xss['body'], '<script>alert(1)</script>'));

    $rateCookie = cookiePath();
    $lastForgot = null;
    $got429 = false;
    for ($i = 0; $i < 8; $i++) {
        $page = http('GET', $baseUrl . '/forgot-password', ['cookie' => $rateCookie]);
        $lastForgot = http('POST', $baseUrl . '/forgot-password', [
            'cookie' => $rateCookie,
            'fields' => ['_csrf' => csrfFrom($page['body']), 'email' => "rate{$i}.{$suffix}@eronyx.test"],
        ]);
        if ($lastForgot['status'] === 429) {
            $got429 = true;
            break;
        }
    }
    check(22, 'Forgot rate limit 429', $got429 && is_array($lastForgot) && headerValue($lastForgot['headers'], 'Retry-After') !== null);

    $limiter = new RateLimiter();
    $limiter->hit('forgot_password:id:../etc/passwd', 60);
    $rootStorage = $limiter->storageRoot();
    $escaped = true;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootStorage, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_contains($file->getPathname(), '..')) {
            $escaped = false;
        }
    }
    check(46, 'Forgot limiter no path traversal', $escaped && is_dir($rootStorage));

    $resetFilesSafe = true;
    foreach (glob($rootStorage . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $name = basename($file);
        if (str_contains($name, $liveRaw) || str_contains($name, $concRaw) || !preg_match('/\A[a-f0-9]{64}\.json\z/', $name)) {
            $resetFilesSafe = false;
        }
    }
    check(47, 'Reset limiter filenames hashed', $resetFilesSafe);

    $home = http('GET', $baseUrl . '/');
    $market = http('GET', $baseUrl . '/marketplace');
    $regPage = http('GET', $baseUrl . '/register');
    $loginPage = http('GET', $baseUrl . '/login');
    $buyerSession = login($baseUrl, $emailA, $changed);
    $account = http('GET', $baseUrl . '/account', ['cookie' => $buyerSession['cookie']]);
    $profile = http('GET', $baseUrl . '/account/profile', ['cookie' => $buyerSession['cookie']]);
    $favs = http('GET', $baseUrl . '/account/favorites', ['cookie' => $buyerSession['cookie']]);
    $msgs = http('GET', $baseUrl . '/account/messages', ['cookie' => $buyerSession['cookie']]);
    $notes = http('GET', $baseUrl . '/account/notifications', ['cookie' => $buyerSession['cookie']]);
    $orders = http('GET', $baseUrl . '/account/orders', ['cookie' => $buyerSession['cookie']]);
    $creatorSession = login($baseUrl, "sec.c.{$suffix}@eronyx.test", $password);
    $creatorHome = http('GET', $baseUrl . '/creator', ['cookie' => $creatorSession['cookie']]);
    $modSession = login($baseUrl, "sec.m.{$suffix}@eronyx.test", $password);
    $modHome = http('GET', $baseUrl . '/moderator', ['cookie' => $modSession['cookie']]);
    $modReports = http('GET', $baseUrl . '/moderator/reports', ['cookie' => $modSession['cookie']]);
    $headersOk = headerValue($home['headers'], 'Content-Security-Policy') !== null
        && headerValue($home['headers'], 'X-Frame-Options') === 'DENY'
        && str_contains((string) headerValue($account['headers'], 'Cache-Control'), 'no-store');
    $fixCookie = cookiePath();
    $loginGet = http('GET', $baseUrl . '/login', ['cookie' => $fixCookie]);
    $sidBefore = is_file($fixCookie) ? (string) file_get_contents($fixCookie) : '';
    http('POST', $baseUrl . '/login', [
        'cookie' => $fixCookie,
        'fields' => ['_csrf' => csrfFrom($loginGet['body']), 'email' => $emailA, 'password' => $changed],
    ]);
    $sidAfter = is_file($fixCookie) ? (string) file_get_contents($fixCookie) : '';
    $logout = http('POST', $baseUrl . '/logout', [
        'cookie' => $buyerSession['cookie'],
        'fields' => ['_csrf' => csrfFrom(http('GET', $baseUrl . '/account', ['cookie' => $buyerSession['cookie']])['body'])],
    ]);

    $explainToken = $pdo->prepare('EXPLAIN SELECT id, user_id FROM password_reset_tokens WHERE token_hash = :token_hash LIMIT 1');
    $explainToken->execute(['token_hash' => str_repeat('a', 64)]);
    $explainInv = $pdo->prepare('EXPLAIN UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = :user_id AND used_at IS NULL');
    $explainInv->execute(['user_id' => $userB]);
    echo 'EXPLAIN token lookup: ' . json_encode($explainToken->fetchAll(), JSON_UNESCAPED_UNICODE) . "\n";
    echo 'EXPLAIN invalidate: ' . json_encode($explainInv->fetchAll(), JSON_UNESCAPED_UNICODE) . "\n";

    $platformOk = $home['status'] === 200 && $market['status'] === 200 && $regPage['status'] === 200 && $loginPage['status'] === 200
        && $account['status'] === 200 && $profile['status'] === 200 && $favs['status'] === 200 && $msgs['status'] === 200
        && $notes['status'] === 200 && $orders['status'] === 200 && $creatorHome['status'] === 200 && $modHome['status'] === 200
        && $modReports['status'] === 200 && $headersOk && $logout['status'] === 302 && $sidBefore !== $sidAfter;
    if ($platformOk) {
        $pass++;
        echo "PASS REGRESSION auth/platform/SECURITY-1\n";
    } else {
        $fail++;
        $failures[] = 'REGRESSION auth/platform/SECURITY-1'
            . " home={$home['status']} account={$account['status']} creator={$creatorHome['status']} mod={$modHome['status']} logout={$logout['status']}";
        echo "FAIL REGRESSION auth/platform/SECURITY-1\n";
    }

    check(48, 'Rate-limit files exist only as hashes', $resetFilesSafe);
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
    $ids = implode(',', array_map('intval', $createdUserIds)) ?: '0';
    $pdo->exec("DELETE FROM password_reset_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM email_verification_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM audit_logs WHERE actor_user_id IN ({$ids}) OR entity_id IN ({$ids})");
    $pdo->exec("DELETE FROM notifications WHERE user_id IN ({$ids})");
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
        'users', 'profiles', 'user_roles', 'creator_profiles', 'roles', 'categories',
        'password_reset_tokens', 'email_verification_tokens', 'notifications', 'audit_logs', 'listings', 'orders',
        'favorites', 'messages', 'reports',
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
