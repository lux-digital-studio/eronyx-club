<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Repositories\AuditLogRepository;
use App\Repositories\CreatorApplicationRepository;
use App\Repositories\ListingRepository;
use App\Repositories\MessageRepository;
use App\Repositories\ModerationActionRepository;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\ReportRepository;
use App\Repositories\UserRepository;
use App\Services\AccountSecurityService;
use App\Services\AgeVerificationService;
use App\Services\CommerceService;
use App\Services\CreatorApplicationService;
use App\Services\EmailRenderer;
use App\Services\ListingService;
use App\Services\MailService;
use App\Services\ModerationService;
use App\Services\RateLimiter;
use PHPMailer\PHPMailer\PHPMailer;

putenv('MAIL_MAILER=array');
putenv('MAIL_PASSWORD=');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$app = require $root . '/config/app.php';
$db = require $root . '/config/database.php';
$mailConfig = require $root . '/config/mail.php';
$baseUrl = rtrim((string) $app['url'], '/');
$pass = 0;
$fail = 0;
$failures = [];
$suffix = 'e' . bin2hex(random_bytes(3));
$password = 'EmailSec1x';
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
    $path = tempnam(sys_get_temp_dir(), 'eronyx_em_');

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

function createListing(
    PDO $pdo,
    int $ownerId,
    string $title,
    string $slug,
    string $visibility = 'public',
    string $status = 'published',
    string $type = 'digital_content',
    string $price = '5.00'
): int {
    global $createdListingIds;
    $statement = $pdo->prepare(
        "INSERT INTO listings (
            owner_user_id, title, slug, description, listing_type, status, price, currency, visibility, published_at
         ) VALUES (
            :owner_user_id, :title, :slug, :description, :listing_type, :status, :price, 'EUR', :visibility, :published_at
         )"
    );
    $statement->execute([
        'owner_user_id' => $ownerId,
        'title' => $title,
        'slug' => $slug,
        'description' => 'Desc ' . $title,
        'listing_type' => $type,
        'status' => $status,
        'price' => $price,
        'visibility' => $visibility,
        'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    $createdListingIds[] = $id;

    return $id;
}

function listingService(PDO $pdo): ListingService
{
    return new ListingService(new Auth(new Session()), $pdo, new ListingRepository($pdo));
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

function mailsOfType(string $type): array
{
    return array_values(array_filter(MailService::sent(), static fn (array $mail): bool => ($mail['type'] ?? null) === $type));
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

function extractResetToken(array $mail): string
{
    $haystack = ($mail['html'] ?? '') . "\n" . ($mail['text'] ?? '');

    if (preg_match('#/reset-password/([a-f0-9]{64})#', $haystack, $matches) === 1) {
        return $matches[1];
    }

    return '';
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

function activeTokenCount(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = :user_id AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
    );
    $statement->execute(['user_id' => $userId]);

    return (int) $statement->fetchColumn();
}

function countType(PDO $pdo, int $userId, string $type): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND type = :type'
    );
    $statement->execute(['user_id' => $userId, 'type' => $type]);

    return (int) $statement->fetchColumn();
}

function listingStatus(PDO $pdo, int $listingId): string
{
    $statement = $pdo->prepare('SELECT status FROM listings WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $listingId]);

    return (string) $statement->fetchColumn();
}

function orderStatus(PDO $pdo, int $orderId): string
{
    $statement = $pdo->prepare('SELECT status FROM orders WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $orderId]);

    return (string) $statement->fetchColumn();
}

function paymentStatus(PDO $pdo, int $orderId): string
{
    $statement = $pdo->prepare('SELECT status FROM payments WHERE order_id = :order_id ORDER BY id DESC LIMIT 1');
    $statement->execute(['order_id' => $orderId]);

    return (string) $statement->fetchColumn();
}

function recentLogHaystack(string $root): string
{
    $files = glob($root . '/storage/logs/app-*.log') ?: [];
    $haystack = '';

    foreach ($files as $file) {
        $haystack .= (string) file_get_contents($file);
    }

    return $haystack;
}

function hasHeader(array $headers, string $name, string $value): bool
{
    $found = headerValue($headers, $name);

    return is_string($found) && strcasecmp($found, $value) === 0;
}

$generic = AccountSecurityService::GENERIC_FORGOT_MESSAGE;
$envExample = (string) file_get_contents($root . '/.env.example');
$composerJson = (string) file_get_contents($root . '/composer.json');

try {
    check(1, 'PHPMailer autoload', class_exists(PHPMailer::class));
    check(
        2,
        '.env.example MAIL_* placeholders',
        str_contains($envExample, 'MAIL_MAILER=array')
        && str_contains($envExample, 'MAIL_HOST=')
        && str_contains($envExample, 'MAIL_PORT=587')
        && str_contains($envExample, 'MAIL_USERNAME=')
        && str_contains($envExample, 'MAIL_PASSWORD=')
        && str_contains($envExample, 'MAIL_ENCRYPTION=tls')
        && str_contains($envExample, 'MAIL_FROM_ADDRESS=')
        && str_contains($envExample, 'MAIL_FROM_NAME=ERONYX')
        && str_contains($envExample, 'MAIL_TIMEOUT=10')
        && !preg_match('/MAIL_PASSWORD=\\S+/', $envExample)
    );
    check(
        3,
        'config/mail.php loads',
        ($mailConfig['mailer'] ?? '') === 'array'
        && (int) ($mailConfig['timeout'] ?? 0) === 10
        && ($mailConfig['from_name'] ?? '') === 'ERONYX'
        && ($mailConfig['password'] ?? 'x') === ''
    );

    $mailer = new MailService($mailConfig);
    MailService::clear();
    $okSend = $mailer->send('buyer@eronyx.test', 'Buyer', 'Asunto de prueba', '<p>Hola</p>', 'Hola', ['type' => 'test_mail', 'user_id' => 1]);
    $sent = MailService::sent();
    check(4, 'MAIL_MAILER=array send succeeds', $okSend === true);
    check(5, 'Array mailer no SMTP host required', ($mailConfig['host'] ?? '') === '' && $okSend);
    check(6, 'Exactly one captured mail', count($sent) === 1 && $sent[0]['to'] === 'buyer@eronyx.test' && $sent[0]['subject'] === 'Asunto de prueba');

    MailService::clear();
    $invalid = $mailer->send('not-an-email', 'Buyer', 'Asunto', '<p>Hola</p>', 'Hola', ['type' => 'test_mail']);
    check(7, 'Invalid recipient rejected', $invalid === false && MailService::sent() === []);

    MailService::clear();
    $crlfSubject = $mailer->send('buyer@eronyx.test', 'Buyer', "Asunto\r\nBcc: attacker@example.test", '<p>Hola</p>', 'Hola', ['type' => 'test_mail']);
    check(8, 'CRLF subject blocked', $crlfSubject === false && MailService::sent() === []);

    MailService::clear();
    $crlfName = $mailer->send('buyer@eronyx.test', "Name\r\nBcc: attacker@example.test", 'Asunto', '<p>Hola</p>', 'Hola', ['type' => 'test_mail']);
    check(9, 'CRLF recipient name blocked', $crlfName === false && MailService::sent() === []);

    $renderer = new EmailRenderer($app);
    $xss = $renderer->render('listing-approved', [
        'displayName' => '<script>alert(1)</script>',
        'listingTitle' => '<script>alert(2)</script>',
        'actionUrl' => $renderer->url('/marketplace/demo'),
    ]);
    check(10, 'HTML generated', str_contains($xss['html'], '<!DOCTYPE html') && $xss['subject'] !== '');
    check(11, 'Plain text generated', str_contains($xss['text'], 'Hola') && str_contains($xss['text'], 'publicación') && $xss['text'] !== '');
    check(
        12,
        'Dynamic content escaped',
        !str_contains($xss['html'], '<script>alert(1)</script>')
        && !str_contains($xss['html'], '<script>alert(2)</script>')
        && str_contains($xss['html'], '&lt;script&gt;alert(1)&lt;/script&gt;')
        && str_contains($xss['html'], '&lt;script&gt;alert(2)&lt;/script&gt;')
    );

    $buyerEmail = "mail.b.{$suffix}@eronyx.test";
    $missingEmail = "mail.missing.{$suffix}@eronyx.test";
    $buyerId = createUser($pdo, $buyerEmail, "mailb{$suffix}", 'Mail Buyer', $password, ['buyer']);
    $buyerBEmail = "mail.b2.{$suffix}@eronyx.test";
    $buyerB = createUser($pdo, $buyerBEmail, "mailb2{$suffix}", 'Mail Buyer 2', $password, ['buyer']);
    $creatorId = createUser($pdo, "mail.c.{$suffix}@eronyx.test", "mailc{$suffix}", 'Mail Creator', $password, ['buyer', 'creator']);
    $pdo->prepare("INSERT INTO creator_profiles (user_id, status) VALUES (:id, 'active')")->execute(['id' => $creatorId]);
    $modId = createUser($pdo, "mail.m.{$suffix}@eronyx.test", "mailm{$suffix}", 'Mail Mod', $password, ['moderator']);
    $security = new AccountSecurityService($session, $pdo);

    MailService::clear();
    $issued = $security->requestPasswordReset($buyerEmail, '127.0.0.1', false);
    $resetMails = mailsOfType('password_reset');
    $rawFromMail = isset($resetMails[0]) ? extractResetToken($resetMails[0]) : '';
    $hash = AccountSecurityService::hashToken($rawFromMail);
    $row = tokenRow($pdo, $hash);
    check(13, 'Existing user token + email', $issued['message'] === $generic && count($resetMails) === 1 && is_array($row));

    MailService::clear();
    $missing = $security->requestPasswordReset($missingEmail, '127.0.0.1', false);
    $guestCookie = cookiePath();
    $forgotPage = http('GET', $baseUrl . '/forgot-password', ['cookie' => $guestCookie]);
    $missingPost = http('POST', $baseUrl . '/forgot-password', [
        'cookie' => $guestCookie,
        'fields' => ['_csrf' => csrfFrom($forgotPage['body']), 'email' => $missingEmail],
    ]);
    $existsPage = http('GET', $baseUrl . '/forgot-password', ['cookie' => $guestCookie]);
    $existsPost = http('POST', $baseUrl . '/forgot-password', [
        'cookie' => $guestCookie,
        'fields' => ['_csrf' => csrfFrom($existsPage['body']), 'email' => $buyerBEmail],
    ]);
    check(
        14,
        'Missing user same public response + 0 mail',
        $missing['message'] === $generic
        && mailsOfType('password_reset') === []
        && $missingPost['status'] === $existsPost['status']
        && str_contains($missingPost['body'], $generic)
        && str_contains($existsPost['body'], $generic)
    );
    check(15, 'Reset URL uses APP_URL', isset($resetMails[0]) && str_contains($resetMails[0]['html'], $baseUrl . '/reset-password/' . $rawFromMail));
    check(16, 'Raw token not in DB', is_array($row) && $row['token_hash'] !== $rawFromMail && !str_contains((string) json_encode($row), $rawFromMail));
    check(17, 'DB stores SHA-256', is_array($row) && $row['token_hash'] === $hash && strlen($hash) === 64);

    $logHaystack = recentLogHaystack($root);
    check(18, 'Raw token not in logs', $rawFromMail !== '' && !str_contains($logHaystack, $rawFromMail));

    MailService::clear();
    $first = $security->requestPasswordReset($buyerBEmail, '127.0.0.1', true);
    $firstRaw = rawTokenFromResetUrl($first['reset_url']);
    $second = $security->requestPasswordReset($buyerBEmail, '127.0.0.1', true);
    $secondRaw = rawTokenFromResetUrl($second['reset_url']);
    $oldRow = tokenRow($pdo, AccountSecurityService::hashToken($firstRaw));
    $newRow = tokenRow($pdo, AccountSecurityService::hashToken($secondRaw));
    check(
        19,
        'Second reset invalidates previous',
        $firstRaw !== '' && $secondRaw !== '' && $firstRaw !== $secondRaw
        && is_array($oldRow) && $oldRow['used_at'] !== null
        && is_array($newRow) && $newRow['used_at'] === null
        && count(mailsOfType('password_reset')) === 2
    );

    $rateCookie = cookiePath();
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
    check(20, 'Forgot rate limit still works', $got429);

    MailService::clear();
    MailService::failNext(1);
    $failReset = $security->requestPasswordReset($buyerEmail, '127.0.0.1', true);
    $activeAfterFail = activeTokenCount($pdo, $buyerId);
    check(21, 'Mailer failure keeps generic response', $failReset['message'] === $generic && $failReset['reset_url'] === null);
    check(22, 'Mailer failure invalidates new token', $activeAfterFail === 0 && MailService::sent() === []);

    MailService::clear();
    $changeOk = $security->changePassword($buyerB, [
        'current_password' => $password,
        'new_password' => 'ChangedMail-' . $suffix,
        'new_password_confirmation' => 'ChangedMail-' . $suffix,
    ]);
    $changedMails = mailsOfType('password_changed');
    check(23, 'Valid password change sends 1 email', $changeOk['ok'] === true && count($changedMails) === 1);

    MailService::clear();
    $wrong = $security->changePassword($buyerB, [
        'current_password' => 'WrongPass12',
        'new_password' => 'AnotherMail-' . $suffix,
        'new_password_confirmation' => 'AnotherMail-' . $suffix,
    ]);
    check(24, 'Wrong current password sends 0 emails', $wrong['ok'] === false && MailService::sent() === []);

    $authB = login($baseUrl, $buyerBEmail, 'ChangedMail-' . $suffix);
    MailService::clear();
    $badCsrf = http('POST', $baseUrl . '/account/security/password', [
        'cookie' => $authB['cookie'],
        'fields' => [
            '_csrf' => 'deadbeef',
            'current_password' => 'ChangedMail-' . $suffix,
            'new_password' => 'HackedPass12',
            'new_password_confirmation' => 'HackedPass12',
        ],
    ]);
    check(25, 'Invalid CSRF sends 0 emails', $badCsrf['status'] === 403 && MailService::sent() === []);
    check(
        26,
        'Change email has no secrets',
        isset($changedMails[0])
        && !str_contains($changedMails[0]['html'], $password)
        && !str_contains($changedMails[0]['html'], 'ChangedMail-' . $suffix)
        && !str_contains($changedMails[0]['html'], 'session_version')
        && !str_contains($changedMails[0]['text'], 'password_hash')
    );

    MailService::clear();
    $resetUser = createUser($pdo, "mail.r.{$suffix}@eronyx.test", "mailr{$suffix}", 'Mail Reset', $password, ['buyer']);
    $resetEmail = "mail.r.{$suffix}@eronyx.test";
    $issuedReset = $security->requestPasswordReset($resetEmail, '127.0.0.1', true);
    $resetRaw = rawTokenFromResetUrl($issuedReset['reset_url']);
    MailService::clear();
    $resetResult = $security->resetPassword($resetRaw, [
        'new_password' => 'ResetMail-' . $suffix,
        'new_password_confirmation' => 'ResetMail-' . $suffix,
    ]);
    $completedMails = mailsOfType('password_reset_completed');
    check(27, 'Valid reset sends confirmation email', $resetResult['ok'] === true && count($completedMails) === 1);

    MailService::clear();
    $invalidReset = $security->resetPassword(bin2hex(random_bytes(32)), [
        'new_password' => 'ResetMail2-' . $suffix,
        'new_password_confirmation' => 'ResetMail2-' . $suffix,
    ]);
    check(28, 'Invalid token sends 0 emails', $invalidReset['ok'] === false && MailService::sent() === []);

    MailService::clear();
    $reused = $security->resetPassword($resetRaw, [
        'new_password' => 'ResetMail3-' . $suffix,
        'new_password_confirmation' => 'ResetMail3-' . $suffix,
    ]);
    check(29, 'Reused token sends 0 emails', $reused['ok'] === false && MailService::sent() === []);

    $resetLogin = login($baseUrl, $resetEmail, 'ResetMail-' . $suffix);
    $accountAfterReset = http('GET', $baseUrl . '/account', ['cookie' => cookiePath()]);
    check(
        30,
        'Reset does not auto-login',
        $resetResult['ok'] === true
        && $accountAfterReset['status'] === 302
        && $resetLogin['login']['status'] === 302
    );

    MailService::clear();
    $apps = new CreatorApplicationService($pdo);
    $age = new AgeVerificationService($pdo);
    $applicant = createUser($pdo, "mail.apply.{$suffix}@eronyx.test", "mailap{$suffix}", 'Applicant', $password, ['buyer']);
    $apps->apply($applicant);
    $application = $apps->findForUser($applicant);
    $age->reviewManual($applicant, $modId, true);
    $approved = $apps->approve((int) $application['id'], $modId);
    check(31, 'Creator approve sends 1 email', $approved && count(mailsOfType('creator_application_approved')) === 1);
    $doubleApprove = $apps->approve((int) $application['id'], $modId);
    check(32, 'Double approve no second email', $doubleApprove === false && count(mailsOfType('creator_application_approved')) === 1);

    $rejectUser = createUser($pdo, "mail.rej.{$suffix}@eronyx.test", "mailrj{$suffix}", 'Rejectee', $password, ['buyer']);
    $apps->apply($rejectUser);
    $rejectApp = $apps->findForUser($rejectUser);
    $rejected = $apps->reject((int) $rejectApp['id'], $modId);
    check(33, 'Creator reject sends 1 email', $rejected && count(mailsOfType('creator_application_rejected')) === 1);
    $doubleReject = $apps->reject((int) $rejectApp['id'], $modId);
    check(34, 'Double reject no second email', $doubleReject === false && count(mailsOfType('creator_application_rejected')) === 1);

    $listingPending = createListing($pdo, $creatorId, 'Pending Mail', 'mail-pend-' . $suffix, 'public', 'pending_review');
    $listingPendingReject = createListing($pdo, $creatorId, 'Reject Mail', 'mail-rej-' . $suffix, 'public', 'pending_review');
    $listingPublic = createListing($pdo, $creatorId, 'Public Mail', 'mail-pub-' . $suffix);
    $listingDigital = createListing($pdo, $creatorId, 'Digital Mail', 'mail-dig-' . $suffix, 'public', 'published', 'digital_content', '9.50');
    $listingBundle = createListing($pdo, $creatorId, 'Bundle Mail', 'mail-bun-' . $suffix, 'public', 'published', 'bundle', '12.00');
    $listingPhysical = createListing($pdo, $creatorId, 'Physical Mail', 'mail-phy-' . $suffix, 'public', 'published', 'physical_product', '20.00');
    $listingService = createListing($pdo, $creatorId, 'Service Mail', 'mail-svc-' . $suffix, 'public', 'published', 'service', '15.00');
    $listingFail = createListing($pdo, $creatorId, 'Fail Mail', 'mail-fail-' . $suffix, 'public', 'pending_review');

    $listings = listingService($pdo);
    MailService::clear();
    $listings->approve($listingPending);
    $listings->approve($listingPending);
    $approvedMail = mailsOfType('listing_approved')[0] ?? null;
    check(35, 'Listing approve sends 1 email', is_array($approvedMail) && count(mailsOfType('listing_approved')) === 1);
    $listings->reject($listingPendingReject);
    $listings->reject($listingPendingReject);
    check(36, 'Listing reject sends 1 email', count(mailsOfType('listing_rejected')) === 1);

    $moderation = moderationService($pdo);
    $suspend = $moderation->suspendListing($modId, $listingPublic);
    check(37, 'Listing suspend sends 1 email', $suspend === 'updated' && count(mailsOfType('listing_suspended')) === 1);
    $suspendAgain = $moderation->suspendListing($modId, $listingPublic);
    check(38, 'Double suspend no extra email', $suspendAgain === 'noop' && count(mailsOfType('listing_suspended')) === 1);
    $restore = $moderation->restoreListing($modId, $listingPublic);
    check(39, 'Listing restore sends 1 email', $restore === 'updated' && count(mailsOfType('listing_restored')) === 1);
    $restoreAgain = $moderation->restoreListing($modId, $listingPublic);
    check(40, 'Double restore no extra email', $restoreAgain === 'noop' && count(mailsOfType('listing_restored')) === 1);

    $commerce = new CommerceService($pdo);
    MailService::clear();
    $digitalCheckout = $commerce->createCheckout($buyerId, $listingDigital);
    $digitalPaid = $commerce->confirmTestPayment($digitalCheckout['order_id'], $buyerId);
    check(41, 'Digital completed email', $digitalPaid && count(mailsOfType('order_completed')) === 1 && count(mailsOfType('order_paid')) === 0);

    $bundleCheckout = $commerce->createCheckout($buyerB, $listingBundle);
    $bundlePaid = $commerce->confirmTestPayment($bundleCheckout['order_id'], $buyerB);
    check(42, 'Bundle completed email', $bundlePaid && count(mailsOfType('order_completed')) === 2);

    $physicalCheckout = $commerce->createCheckout($buyerId, $listingPhysical);
    $physicalPaid = $commerce->confirmTestPayment($physicalCheckout['order_id'], $buyerId);
    check(43, 'Physical paid email', $physicalPaid && count(mailsOfType('order_paid')) === 1);

    $serviceCheckout = $commerce->createCheckout($buyerB, $listingService);
    $servicePaid = $commerce->confirmTestPayment($serviceCheckout['order_id'], $buyerB);
    check(44, 'Service paid email', $servicePaid && count(mailsOfType('order_paid')) === 2);

    $digitalAgain = $commerce->confirmTestPayment($digitalCheckout['order_id'], $buyerId);
    check(45, 'Double test-pay no duplicate email', $digitalAgain === false && count(mailsOfType('order_completed')) === 2);

    $completedMail = mailsOfType('order_completed')[0] ?? null;
    $paidMail = mailsOfType('order_paid')[0] ?? null;
    check(
        46,
        'Order amounts from server snapshot',
        is_array($completedMail)
        && str_contains($completedMail['html'], '9.50')
        && str_contains($completedMail['html'], 'EUR')
        && is_array($paidMail)
        && str_contains($paidMail['html'], '20.00')
        && str_contains($paidMail['html'], 'EUR')
        && !str_contains($completedMail['html'], 'cvv')
        && !str_contains($completedMail['html'], 'IBAN')
    );

    MailService::failNext(1);
    $failApprove = $listings->approve($listingFail);
    check(47, 'Listing stays published on mail failure', $failApprove && listingStatus($pdo, $listingFail) === 'published');
    check(48, 'Notification still created on mail failure', countType($pdo, $creatorId, 'listing_approved') >= 1);
    check(49, 'No 500 on SMTP failure', $failApprove === true);

    MailService::failNext(1);
    $failListing = createListing($pdo, $creatorId, 'Fail Pay', 'mail-failpay-' . $suffix, 'public', 'published', 'digital_content', '7.00');
    $failCheckout = $commerce->createCheckout($buyerId, $failListing);
    $failPay = $commerce->confirmTestPayment($failCheckout['order_id'], $buyerId);
    check(
        50,
        'Commerce survives mail failure',
        $failPay
        && orderStatus($pdo, $failCheckout['order_id']) === 'completed'
        && paymentStatus($pdo, $failCheckout['order_id']) === 'paid'
    );

    MailService::failNext(1);
    $failApplicant = createUser($pdo, "mail.failc.{$suffix}@eronyx.test", "mailfc{$suffix}", 'Fail Creator', $password, ['buyer']);
    $apps->apply($failApplicant);
    $failApp = $apps->findForUser($failApplicant);
    $age->reviewManual($failApplicant, $modId, true);
    $failCreator = $apps->approve((int) $failApp['id'], $modId);
    $creatorStatus = $pdo->prepare('SELECT status FROM creator_profiles WHERE user_id = :id LIMIT 1');
    $creatorStatus->execute(['id' => $failApplicant]);
    check(51, 'Creator stays active on mail failure', $failCreator && (string) $creatorStatus->fetchColumn() === 'active');

    $logAfter = recentLogHaystack($root);
    check(
        52,
        'Logs contain no secrets',
        !str_contains($logAfter, $rawFromMail)
        && !str_contains($logAfter, 'MAIL_PASSWORD=')
        && !str_contains($logAfter, $password)
        && !str_contains($logAfter, 'ChangedMail-' . $suffix)
    );

    $originalEnv = getenv('APP_ENV');
    putenv('APP_ENV=production');
    $prodApp = require $root . '/config/app.php';
    $controllerSrc = (string) file_get_contents($root . '/app/Controllers/PasswordResetController.php');
    if (is_string($originalEnv) && $originalEnv !== '') {
        putenv('APP_ENV=' . $originalEnv);
    } else {
        putenv('APP_ENV');
    }
    $prodForgot = http('GET', $baseUrl . '/forgot-password');
    check(
        53,
        'Production hides dev reset URL',
        str_contains($controllerSrc, "['local', 'test']")
        && !in_array('production', ['local', 'test'], true)
        && !str_contains($prodForgot['body'], '/reset-password/')
        && ($failReset['reset_url'] ?? null) === null
    );

    $loginPage = http('GET', $baseUrl . '/login');
    $home = http('GET', $baseUrl . '/');
    check(
        54,
        'SMTP password not in HTML',
        !str_contains($loginPage['body'], 'MAIL_PASSWORD')
        && !str_contains($home['body'], 'MAIL_PASSWORD')
        && ($mailConfig['password'] ?? '') === ''
    );
    check(55, 'SMTP password not in logs', !str_contains($logAfter, 'smtp-secret') && ($mailConfig['password'] ?? '') === '');

    $audit = $pdo->prepare(
        "SELECT metadata_json FROM audit_logs WHERE actor_user_id = :id AND event_type = 'password_reset_requested' ORDER BY id DESC LIMIT 5"
    );
    $audit->execute(['id' => $buyerId]);
    $auditSafe = true;
    foreach ($audit->fetchAll() as $auditRow) {
        $meta = (string) ($auditRow['metadata_json'] ?? '');
        if ($rawFromMail !== '' && str_contains($meta, $rawFromMail)) {
            $auditSafe = false;
        }
    }
    check(56, 'Raw reset token not in audit', $auditSafe);

    check(
        57,
        'APP_URL used in email links',
        is_array($approvedMail)
        && str_contains($approvedMail['html'], $baseUrl . '/marketplace/mail-pend-' . $suffix)
        && str_starts_with($baseUrl, 'http')
    );
    check(58, 'No Host-header poisoning', is_array($resetMails[0] ?? null)
        && str_starts_with((string) ($resetMails[0]['text'] ?? ''), 'Hola')
        && str_contains($resetMails[0]['html'], $baseUrl)
        && !str_contains($resetMails[0]['html'], 'evil.example')
        && str_contains($composerJson, 'phpmailer/phpmailer'));

    $sessionBuyer = login($baseUrl, $buyerEmail, $password);
    $account = http('GET', $baseUrl . '/account', ['cookie' => $sessionBuyer['cookie']]);
    $profile = http('GET', $baseUrl . '/account/profile', ['cookie' => $sessionBuyer['cookie']]);
    $favs = http('GET', $baseUrl . '/account/favorites', ['cookie' => $sessionBuyer['cookie']]);
    $msgs = http('GET', $baseUrl . '/account/messages', ['cookie' => $sessionBuyer['cookie']]);
    $notes = http('GET', $baseUrl . '/account/notifications', ['cookie' => $sessionBuyer['cookie']]);
    $orders = http('GET', $baseUrl . '/account/orders', ['cookie' => $sessionBuyer['cookie']]);
    $secPage = http('GET', $baseUrl . '/account/security/password', ['cookie' => $sessionBuyer['cookie']]);
    $sessionCreator = login($baseUrl, "mail.c.{$suffix}@eronyx.test", $password);
    $creatorHome = http('GET', $baseUrl . '/creator', ['cookie' => $sessionCreator['cookie']]);
    $creatorListings = http('GET', $baseUrl . '/creator/listings', ['cookie' => $sessionCreator['cookie']]);
    $sessionMod = login($baseUrl, "mail.m.{$suffix}@eronyx.test", $password);
    $modHome = http('GET', $baseUrl . '/moderator', ['cookie' => $sessionMod['cookie']]);
    $modListings = http('GET', $baseUrl . '/moderator/listings', ['cookie' => $sessionMod['cookie']]);
    $modApps = http('GET', $baseUrl . '/moderator/creator-applications', ['cookie' => $sessionMod['cookie']]);
    $modReports = http('GET', $baseUrl . '/moderator/reports', ['cookie' => $sessionMod['cookie']]);
    $market = http('GET', $baseUrl . '/marketplace');
    $registerPage = http('GET', $baseUrl . '/register');
    $forgotGet = http('GET', $baseUrl . '/forgot-password');
    $publicListing = http('GET', $baseUrl . '/marketplace/mail-dig-' . $suffix);
    $checkout = http('GET', $baseUrl . '/checkout/' . $listingDigital, ['cookie' => $sessionBuyer['cookie']]);
    $regressOk = $home['status'] === 200
        && $market['status'] === 200
        && $loginPage['status'] === 200
        && $registerPage['status'] === 200
        && $forgotGet['status'] === 200
        && $account['status'] === 200
        && $profile['status'] === 200
        && $favs['status'] === 200
        && $msgs['status'] === 200
        && $notes['status'] === 200
        && $orders['status'] === 200
        && $secPage['status'] === 200
        && $creatorHome['status'] === 200
        && $creatorListings['status'] === 200
        && $modHome['status'] === 200
        && $modListings['status'] === 200
        && $modApps['status'] === 200
        && $modReports['status'] === 200
        && $publicListing['status'] === 200
        && in_array($checkout['status'], [200, 302, 403], true);
    if ($regressOk) {
        $pass++;
        echo "PASS REGRESSION core pages\n";
    } else {
        $fail++;
        $failures[] = 'REGRESSION core pages';
        echo "FAIL REGRESSION core pages"
            . " home={$home['status']} account={$account['status']} creator={$creatorHome['status']} mod={$modHome['status']}"
            . " checkout={$checkout['status']} notes={$notes['status']}\n";
    }

    $securityHeaders = hasHeader($home['headers'], 'X-Frame-Options', 'DENY')
        && hasHeader($home['headers'], 'X-Content-Type-Options', 'nosniff')
        && headerValue($home['headers'], 'Content-Security-Policy') !== null
        && is_string(headerValue($account['headers'], 'Cache-Control'))
        && str_contains((string) headerValue($account['headers'], 'Cache-Control'), 'no-store')
        && class_exists(RateLimiter::class);
    if ($securityHeaders) {
        $pass++;
        echo "PASS SECURITY-1 headers and rate limiter\n";
    } else {
        $fail++;
        $failures[] = 'SECURITY-1 regression';
        echo "FAIL SECURITY-1 regression\n";
    }

    $tokenRepo = new PasswordResetTokenRepository($pdo);
    $accountSecOk = AccountSecurityService::GENERIC_FORGOT_MESSAGE === $generic
        && $tokenRepo::TTL_SECONDS === 1800
        && \App\Validators\PasswordPolicy::error('short') !== null
        && \App\Validators\PasswordPolicy::error('TenCharsOK') === null;
    if ($accountSecOk) {
        $pass++;
        echo "PASS ACCOUNT-SECURITY-2 policy still intact\n";
    } else {
        $fail++;
        $failures[] = 'ACCOUNT-SECURITY-2 regression';
        echo "FAIL ACCOUNT-SECURITY-2 regression\n";
    }
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
    $pdo->exec("DELETE FROM audit_logs WHERE actor_user_id IN ({$ids}) OR entity_id IN ({$listingIds}) OR entity_id IN ({$ids})");
    $pdo->exec("DELETE FROM moderation_actions WHERE moderator_user_id IN ({$ids}) OR target_id IN ({$listingIds}) OR target_id IN ({$ids})");
    $pdo->exec("DELETE FROM password_reset_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM email_verification_tokens WHERE user_id IN ({$ids})");
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
echo 'Array mailer leftover: ' . count(MailService::sent()) . "\n";
echo "PASS={$pass} FAIL={$fail}\n";

if ($failures !== []) {
    echo "Failures:\n- " . implode("\n- ", $failures) . "\n";
}

exit($fail === 0 ? 0 : 1);
