<?php

declare(strict_types=1);

use App\Core\Session;
use App\Repositories\EmailVerificationTokenRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\EmailVerificationService;
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
$suffix = 'v' . bin2hex(random_bytes(3));
$password = 'VerifySec1x';
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
$session = new Session();
$session->start();

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
    $path = tempnam(sys_get_temp_dir(), 'eronyx_ev_');

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

function createUser(PDO $pdo, string $email, string $username, string $display, string $password, array $roles, bool $verified = true): int
{
    global $createdUserIds;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $sql = $verified
        ? "INSERT INTO users (email, password_hash, status, email_verified_at) VALUES (:email, :password_hash, 'active', CURRENT_TIMESTAMP)"
        : "INSERT INTO users (email, password_hash, status) VALUES (:email, :password_hash, 'active')";
    $statement = $pdo->prepare($sql);
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

function createListing(PDO $pdo, int $ownerId, string $title, string $slug): int
{
    global $createdListingIds;
    $statement = $pdo->prepare(
        "INSERT INTO listings (
            owner_user_id, title, slug, description, listing_type, status, price, currency, visibility, published_at
         ) VALUES (
            :owner_user_id, :title, :slug, :description, 'digital_content', 'published', '5.00', 'EUR', 'public', CURRENT_TIMESTAMP
         )"
    );
    $statement->execute([
        'owner_user_id' => $ownerId,
        'title' => $title,
        'slug' => $slug,
        'description' => 'Desc ' . $title,
    ]);
    $id = (int) $pdo->lastInsertId();
    $createdListingIds[] = $id;

    return $id;
}

function redirectedToVerify(array $response): bool
{
    $location = headerValue($response['headers'], 'Location') ?? '';

    return $response['status'] === 302 && str_contains($location, '/account/verify-email');
}

function extractVerifyToken(array $mail): string
{
    $haystack = ($mail['html'] ?? '') . "\n" . ($mail['text'] ?? '');

    if (preg_match('#/verify-email/([a-f0-9]{64})#', $haystack, $matches) === 1) {
        return $matches[1];
    }

    return '';
}

function tokenRow(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, user_id, token_hash, expires_at, used_at
         FROM email_verification_tokens
         WHERE user_id = :user_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function activeTokenCount(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM email_verification_tokens
         WHERE user_id = :user_id AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
    );
    $statement->execute(['user_id' => $userId]);

    return (int) $statement->fetchColumn();
}

function runMigrate(string $root): array
{
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrate.php') . ' 2>&1', $output, $code);

    return ['code' => $code, 'output' => implode("\n", $output)];
}

function containsRawToken(PDO $pdo, string $rawToken): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM email_verification_tokens WHERE token_hash = :raw OR requested_ip_hash = :raw_ip'
    );
    $statement->execute(['raw' => $rawToken, 'raw_ip' => $rawToken]);

    return (int) $statement->fetchColumn() > 0;
}

try {
    $firstMigrate = runMigrate($root);
    $secondMigrate = runMigrate($root);
    $migrationName = '2026_08_21_000012_create_email_verification_tokens_table';
    $migrated = (int) $pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = " . $pdo->quote($migrationName))->fetchColumn();
    check(1, 'Migration aplica', $firstMigrate['code'] === 0 && $migrated === 1 && str_contains($firstMigrate['output'] . $secondMigrate['output'], 'Migrations complete.'));
    check(2, 'Segunda ejecución idempotente', $secondMigrate['code'] === 0 && !str_contains($secondMigrate['output'], 'Migrated: ' . $migrationName) && str_contains($secondMigrate['output'], 'Migrations complete.'));

    $columns = $pdo->query('SHOW COLUMNS FROM email_verification_tokens')->fetchAll();
    $columnNames = array_map(static fn (array $row): string => (string) $row['Field'], $columns);
    $tokenHash = null;
    $usedAt = null;
    foreach ($columns as $column) {
        if ($column['Field'] === 'token_hash') {
            $tokenHash = $column;
        }
        if ($column['Field'] === 'used_at') {
            $usedAt = $column;
        }
    }
    $usersVerified = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_verified_at'")->fetch();
    check(
        3,
        'Schema correcto',
        is_array($usersVerified)
        && $columnNames === ['id', 'user_id', 'token_hash', 'expires_at', 'used_at', 'requested_ip_hash', 'created_at']
        && is_array($tokenHash)
        && str_contains(strtolower((string) $tokenHash['Type']), 'char(64)')
        && is_array($usedAt)
        && strtoupper((string) $usedAt['Null']) === 'YES'
    );

    $indexes = $pdo->query('SHOW INDEX FROM email_verification_tokens')->fetchAll();
    $uniqueToken = false;
    $userIndex = false;
    $expiresIndex = false;
    foreach ($indexes as $index) {
        $name = (string) $index['Key_name'];
        $nonUnique = (int) $index['Non_unique'];
        if ($name === 'email_verification_tokens_token_hash_unique' && $nonUnique === 0 && $index['Column_name'] === 'token_hash') {
            $uniqueToken = true;
        }
        if ($name === 'email_verification_tokens_user_id_index' && $index['Column_name'] === 'user_id') {
            $userIndex = true;
        }
        if ($name === 'email_verification_tokens_expires_at_index' && $index['Column_name'] === 'expires_at') {
            $expiresIndex = true;
        }
    }
    $fk = $pdo->query(
        "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND TABLE_NAME = 'email_verification_tokens'
            AND CONSTRAINT_NAME = 'email_verification_tokens_user_id_foreign'"
    )->fetchColumn();
    check(4, 'FK/index correctos', $uniqueToken && $userIndex && $expiresIndex && $fk === 'CASCADE');

    MailService::clear();
    $auth = new AuthService($session, $pdo, new UserRepository($pdo));
    $regEmail = "reg.{$suffix}@eronyx.test";
    $regId = $auth->register([
        'email' => $regEmail,
        'username' => "reg{$suffix}",
        'display_name' => 'Reg Verify',
        'password' => $password,
    ], '127.0.0.1');
    $createdUserIds[] = $regId;
    $verifiedAt = $pdo->query('SELECT email_verified_at FROM users WHERE id = ' . (int) $regId)->fetchColumn();
    check(5, 'Registro email_verified_at NULL', $verifiedAt === null);

    $row = tokenRow($pdo, $regId);
    $mails = MailService::sent();
    $rawToken = $mails !== [] ? extractVerifyToken($mails[0]) : '';
    check(6, 'Genera token hashed', is_array($row) && preg_match('/\A[a-f0-9]{64}\z/', (string) $row['token_hash']) === 1 && $rawToken !== '' && hash('sha256', $rawToken) === $row['token_hash']);
    check(7, 'Envía 1 email array', count($mails) === 1 && ($mails[0]['type'] ?? null) === 'email_verification' && ($mails[0]['subject'] ?? '') === 'Verifica tu correo de ERONYX');
    check(8, 'Raw token no DB', $rawToken !== '' && !containsRawToken($pdo, $rawToken));
    check(9, 'Link usa APP_URL', $rawToken !== '' && str_contains((string) ($mails[0]['html'] ?? ''), $baseUrl . '/verify-email/' . $rawToken));

    $verify = http('GET', $baseUrl . '/verify-email/' . $rawToken);
    $verifiedAfter = $pdo->query('SELECT email_verified_at FROM users WHERE id = ' . (int) $regId)->fetchColumn();
    $usedAfter = tokenRow($pdo, $regId);
    check(10, 'Token válido verifica', $verify['status'] === 302 && is_string($verifiedAfter) && $verifiedAfter !== '');
    check(11, 'used_at NOT NULL', is_array($usedAfter) && is_string($usedAfter['used_at']) && $usedAfter['used_at'] !== '');

    $reuse = http('GET', $baseUrl . '/verify-email/' . $rawToken);
    $verifiedReuse = $pdo->query('SELECT email_verified_at FROM users WHERE id = ' . (int) $regId)->fetchColumn();
    check(12, 'Reusar token invalid', $reuse['status'] === 404 && str_contains($reuse['body'], 'Enlace no válido') && $verifiedReuse === $verifiedAfter);

    $expiredUser = createUser($pdo, "exp.{$suffix}@eronyx.test", "exp{$suffix}", 'Exp User', $password, ['buyer'], false);
    $expiredRaw = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, used_at)
         VALUES (:user_id, :token_hash, DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE), NULL)'
    )->execute(['user_id' => $expiredUser, 'token_hash' => hash('sha256', $expiredRaw)]);
    $expiredGet = http('GET', $baseUrl . '/verify-email/' . $expiredRaw);
    $expiredVerified = $pdo->query('SELECT email_verified_at FROM users WHERE id = ' . (int) $expiredUser)->fetchColumn();
    check(13, 'Expired invalid', $expiredGet['status'] === 404 && $expiredVerified === null);

    $randomGet = http('GET', $baseUrl . '/verify-email/' . bin2hex(random_bytes(32)));
    check(14, 'Random invalid', $randomGet['status'] === 404 && str_contains($randomGet['body'], 'Enlace no válido'));

    $sqli = http('GET', $baseUrl . '/verify-email/' . rawurlencode("1' OR 1=1 --"));
    check(15, 'SQLi token no 500', $sqli['status'] !== 500 && in_array($sqli['status'], [404, 400], true));

    MailService::clear();
    $pending = createUser($pdo, "pend.{$suffix}@eronyx.test", "pend{$suffix}", 'Pend User', $password, ['buyer'], false);
    $verification = new EmailVerificationService($pdo, new UserRepository($pdo), new EmailVerificationTokenRepository($pdo));
    $firstSend = $verification->issueForUser($pending, '127.0.0.1');
    $oldHash = (string) (tokenRow($pdo, $pending)['token_hash'] ?? '');
    MailService::clear();
    $resend = $verification->resend($pending, '127.0.0.1');
    $newRow = tokenRow($pdo, $pending);
    $newMails = MailService::sent();
    $oldUsed = $pdo->prepare('SELECT used_at FROM email_verification_tokens WHERE token_hash = :hash');
    $oldUsed->execute(['hash' => $oldHash]);
    $oldUsedAt = $oldUsed->fetchColumn();
    check(16, 'Pending resend nuevo token', $firstSend && $resend['mailed'] === true && is_array($newRow) && $newRow['token_hash'] !== $oldHash);
    check(17, 'Anterior invalidado', is_string($oldUsedAt) && $oldUsedAt !== '');
    check(18, '1 email nuevo', count($newMails) === 1 && ($newMails[0]['type'] ?? null) === 'email_verification');

    MailService::clear();
    $verifiedResendUser = createUser($pdo, "vres.{$suffix}@eronyx.test", "vres{$suffix}", 'VRes User', $password, ['buyer'], true);
    $verifiedResend = $verification->resend($verifiedResendUser, '127.0.0.1');
    check(19, 'Verified resend no email', $verifiedResend['already_verified'] === true && $verifiedResend['mailed'] === false && MailService::sent() === [] && activeTokenCount($pdo, $verifiedResendUser) === 0);

    $rateUser = createUser($pdo, "rate.{$suffix}@eronyx.test", "rate{$suffix}", 'Rate User', $password, ['buyer'], false);
    $rateSession = login($baseUrl, "rate.{$suffix}@eronyx.test", $password);
    $statuses = [];
    $retryAfter = null;
    for ($i = 0; $i < 6; $i++) {
        $page = http('GET', $baseUrl . '/account/verify-email', ['cookie' => $rateSession['cookie']]);
        $post = http('POST', $baseUrl . '/account/verify-email/resend', [
            'cookie' => $rateSession['cookie'],
            'fields' => ['_csrf' => csrfFrom($page['body'])],
        ]);
        $statuses[] = $post['status'];
        if ($post['status'] === 429) {
            $retryAfter = headerValue($post['headers'], 'Retry-After');
        }
    }
    $limited = array_values(array_filter($statuses, static fn (int $status): bool => $status === 429));
    check(20, 'Rate limit 429', count($limited) >= 1 && is_string($retryAfter) && ctype_digit($retryAfter));

    MailService::clear();
    $csrfUser = createUser($pdo, "csrf.{$suffix}@eronyx.test", "csrf{$suffix}", 'Csrf User', $password, ['buyer'], false);
    $csrfSession = login($baseUrl, "csrf.{$suffix}@eronyx.test", $password);
    $beforeTokens = (int) $pdo->query('SELECT COUNT(*) FROM email_verification_tokens WHERE user_id = ' . (int) $csrfUser)->fetchColumn();
    $badCsrf = http('POST', $baseUrl . '/account/verify-email/resend', [
        'cookie' => $csrfSession['cookie'],
        'fields' => ['_csrf' => 'invalid'],
    ]);
    $afterTokens = (int) $pdo->query('SELECT COUNT(*) FROM email_verification_tokens WHERE user_id = ' . (int) $csrfUser)->fetchColumn();
    check(21, 'CSRF inválido no token/email', $badCsrf['status'] === 403 && $afterTokens === $beforeTokens && MailService::sent() === []);

    MailService::clear();
    MailService::failNext(1);
    $failUser = createUser($pdo, "fail.{$suffix}@eronyx.test", "fail{$suffix}", 'Fail User', $password, ['buyer'], false);
    $failed = $verification->issueForUser($failUser, '127.0.0.1');
    check(22, 'Mailer failure token invalidado', $failed === false && MailService::sent() === [] && activeTokenCount($pdo, $failUser) === 0);

    $unverified = createUser($pdo, "unv.{$suffix}@eronyx.test", "unv{$suffix}", 'Unv User', $password, ['buyer'], false);
    $unverifiedSession = login($baseUrl, "unv.{$suffix}@eronyx.test", $password);
    $account = http('GET', $baseUrl . '/account', ['cookie' => $unverifiedSession['cookie']]);
    check(23, 'Unverified puede /account', $unverifiedSession['login']['status'] === 302 && $account['status'] === 200 && str_contains($account['body'], 'Debes verificar tu correo.'));

    $apply = http('GET', $baseUrl . '/account/creator/apply', ['cookie' => $unverifiedSession['cookie']]);
    check(24, 'Unverified creator apply bloqueado', redirectedToVerify($apply));

    $unverifiedCreator = createUser($pdo, "ucr.{$suffix}@eronyx.test", "ucr{$suffix}", 'Ucr User', $password, ['buyer', 'creator'], false);
    $pdo->prepare("INSERT INTO creator_profiles (user_id, status) VALUES (:id, 'active')")->execute(['id' => $unverifiedCreator]);
    $unverifiedCreatorSession = login($baseUrl, "ucr.{$suffix}@eronyx.test", $password);
    $createListing = http('GET', $baseUrl . '/creator/listings/create', ['cookie' => $unverifiedCreatorSession['cookie']]);
    check(25, 'Unverified create listing bloqueado', redirectedToVerify($createListing));

    $listingOwner = createUser($pdo, "own.{$suffix}@eronyx.test", "own{$suffix}", 'Own User', $password, ['buyer', 'creator'], true);
    $pdo->prepare("INSERT INTO creator_profiles (user_id, status) VALUES (:id, 'active')")->execute(['id' => $listingOwner]);
    $listingId = createListing($pdo, $listingOwner, 'Verify Listing', "verify-{$suffix}");
    $startMessage = http('POST', $baseUrl . '/messages/start/' . $listingId, [
        'cookie' => $unverifiedSession['cookie'],
        'fields' => ['_csrf' => csrfFrom($account['body'])],
    ]);
    check(26, 'Unverified send message bloqueado', redirectedToVerify($startMessage));

    $fav = http('POST', $baseUrl . '/favorites/' . $listingId, [
        'cookie' => $unverifiedSession['cookie'],
        'fields' => ['_csrf' => csrfFrom($account['body'])],
    ]);
    check(27, 'Unverified favorite POST bloqueado', redirectedToVerify($fav));

    $checkoutGet = http('GET', $baseUrl . '/checkout/' . $listingId, ['cookie' => $unverifiedSession['cookie']]);
    $checkoutPost = http('POST', $baseUrl . '/checkout/' . $listingId, [
        'cookie' => $unverifiedSession['cookie'],
        'fields' => ['_csrf' => csrfFrom($account['body'])],
    ]);
    check(28, 'Unverified checkout bloqueado', redirectedToVerify($checkoutGet) && redirectedToVerify($checkoutPost));

    $report = http('POST', $baseUrl . '/reports/listing/' . $listingId, [
        'cookie' => $unverifiedSession['cookie'],
        'fields' => ['_csrf' => csrfFrom($account['body']), 'reason' => 'spam', 'details' => 'test report'],
    ]);
    check(29, 'Unverified report POST bloqueado', redirectedToVerify($report));

    $verifiedBuyer = createUser($pdo, "vb.{$suffix}@eronyx.test", "vb{$suffix}", 'VB User', $password, ['buyer'], true);
    $verifiedSession = login($baseUrl, "vb.{$suffix}@eronyx.test", $password);
    $verifiedAccount = http('GET', $baseUrl . '/account', ['cookie' => $verifiedSession['cookie']]);
    $verifiedApply = http('GET', $baseUrl . '/account/creator/apply', ['cookie' => $verifiedSession['cookie']]);
    $verifiedCheckout = http('GET', $baseUrl . '/checkout/' . $listingId, ['cookie' => $verifiedSession['cookie']]);
    $verifiedFavList = http('GET', $baseUrl . '/account/favorites', ['cookie' => $verifiedSession['cookie']]);
    $verifiedMessages = http('GET', $baseUrl . '/account/messages', ['cookie' => $verifiedSession['cookie']]);
    $verifiedReportForm = http('GET', $baseUrl . '/reports/listing/' . $listingId, ['cookie' => $verifiedSession['cookie']]);
    $verifiedCreator = createUser($pdo, "vc.{$suffix}@eronyx.test", "vc{$suffix}", 'VC User', $password, ['buyer', 'creator'], true);
    $pdo->prepare("INSERT INTO creator_profiles (user_id, status) VALUES (:id, 'active')")->execute(['id' => $verifiedCreator]);
    $verifiedCreatorSession = login($baseUrl, "vc.{$suffix}@eronyx.test", $password);
    $verifiedCreate = http('GET', $baseUrl . '/creator/listings/create', ['cookie' => $verifiedCreatorSession['cookie']]);
    $verifiedFavPost = http('POST', $baseUrl . '/favorites/' . $listingId, [
        'cookie' => $verifiedSession['cookie'],
        'fields' => ['_csrf' => csrfFrom($verifiedFavList['body'])],
    ]);
    check(
        30,
        'Verified user flujos normales',
        $verifiedAccount['status'] === 200
        && $verifiedApply['status'] === 200
        && $verifiedCheckout['status'] === 200
        && $verifiedFavList['status'] === 200
        && $verifiedMessages['status'] === 200
        && $verifiedReportForm['status'] === 200
        && $verifiedCreate['status'] === 200
        && $verifiedFavPost['status'] === 302
        && !redirectedToVerify($verifiedFavPost)
        && str_contains($verifiedAccount['body'], 'Email verificado')
    );

    check(31, 'Unverified puede login', $unverifiedSession['login']['status'] === 302);
    check(32, 'Session normal', $unverifiedSession['login']['status'] === 302 && $account['status'] === 200);
    check(33, 'UI pendiente', str_contains($account['body'], 'Debes verificar tu correo.') && str_contains($account['body'], 'Reenviar email de verificación'));
    check(34, 'Verified UI correcta', str_contains($verifiedAccount['body'], 'Email verificado') && !str_contains($verifiedAccount['body'], 'Debes verificar tu correo.'));

    $logHit = false;
    foreach (glob($root . '/storage/logs/*.log') ?: [] as $logFile) {
        $contents = (string) file_get_contents($logFile);
        if ($rawToken !== '' && str_contains($contents, $rawToken)) {
            $logHit = true;
            break;
        }
    }
    check(35, 'Raw token no logs', $rawToken !== '' && !$logHit);

    $auditRows = $pdo->query(
        'SELECT event_type, metadata_json FROM audit_logs WHERE actor_user_id IN (' . implode(',', array_map('intval', $createdUserIds)) . ')'
    )->fetchAll();
    $auditLeak = false;
    $hasSent = false;
    $hasVerified = false;
    foreach ($auditRows as $auditRow) {
        $json = (string) ($auditRow['metadata_json'] ?? '');
        if ($rawToken !== '' && str_contains($json, $rawToken)) {
            $auditLeak = true;
        }
        if ($auditRow['event_type'] === 'email_verification_sent') {
            $hasSent = true;
        }
        if ($auditRow['event_type'] === 'email_verified') {
            $hasVerified = true;
        }
    }
    check(36, 'Raw token no audit', !$auditLeak && $hasSent && $hasVerified);

    $noteCount = (int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = ' . (int) $regId)->fetchColumn();
    check(37, 'No token en notifications', $noteCount === 0);

    check(38, 'No email enumeration', $reuse['status'] === 404 && !str_contains($reuse['body'], $regEmail) && !str_contains($reuse['body'], (string) $regId) && !str_contains($randomGet['body'], $regEmail));
    check(39, 'CSRF resend', $badCsrf['status'] === 403);
    check(40, 'Rate limit', count($limited) >= 1);

    $favListUnverified = http('GET', $baseUrl . '/account/favorites', ['cookie' => $unverifiedSession['cookie']]);
    $messagesUnverified = http('GET', $baseUrl . '/account/messages', ['cookie' => $unverifiedSession['cookie']]);
    $notificationsUnverified = http('GET', $baseUrl . '/account/notifications', ['cookie' => $unverifiedSession['cookie']]);
    $publicMarket = http('GET', $baseUrl . '/marketplace');
    check(
        41,
        'Lectura pública/autenticada permitida',
        $favListUnverified['status'] === 200
        && $messagesUnverified['status'] === 200
        && $notificationsUnverified['status'] === 200
        && $publicMarket['status'] === 200
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
    $listingIds = implode(',', array_map('intval', $createdListingIds)) ?: '0';
    $orderIds = $pdo->query("SELECT id FROM orders WHERE buyer_user_id IN ({$ids})")->fetchAll(PDO::FETCH_COLUMN);
    $orderList = $orderIds !== [] ? implode(',', array_map('intval', $orderIds)) : '0';
    $conversationIds = $pdo->query(
        "SELECT conversation_id FROM conversation_participants WHERE user_id IN ({$ids})"
    )->fetchAll(PDO::FETCH_COLUMN);
    $conversationList = $conversationIds !== [] ? implode(',', array_map('intval', $conversationIds)) : '0';

    $pdo->exec("DELETE FROM notifications WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM audit_logs WHERE actor_user_id IN ({$ids}) OR entity_id IN ({$listingIds}) OR entity_id IN ({$ids})");
    $pdo->exec("DELETE FROM email_verification_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM password_reset_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM reports WHERE reporter_user_id IN ({$ids})");
    $pdo->exec("DELETE FROM messages WHERE conversation_id IN ({$conversationList}) OR sender_user_id IN ({$ids})");
    $pdo->exec("DELETE FROM conversation_participants WHERE user_id IN ({$ids}) OR conversation_id IN ({$conversationList})");
    $pdo->exec("DELETE FROM conversations WHERE id IN ({$conversationList}) OR created_by_user_id IN ({$ids})");
    $pdo->exec("DELETE FROM favorites WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM private_content_access WHERE user_id IN ({$ids}) OR listing_id IN ({$listingIds})");
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
        'listings', 'listing_categories', 'media_files', 'listing_media', 'private_content_access',
        'orders', 'order_items', 'payments', 'favorites', 'conversations', 'conversation_participants',
        'messages', 'reports', 'moderation_actions', 'audit_logs', 'notifications', 'password_reset_tokens',
        'email_verification_tokens',
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
echo 'Array mailer leftover: ' . count(MailService::sent()) . "\n";
echo "PASS={$pass} FAIL={$fail}\n";

if ($failures !== []) {
    echo "Failures:\n- " . implode("\n- ", $failures) . "\n";
}

exit($fail === 0 ? 0 : 1);
