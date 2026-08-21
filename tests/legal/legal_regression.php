<?php

declare(strict_types=1);

use App\Services\MailService;
use App\Services\UserConsentService;

putenv('MAIL_MAILER=array');
putenv('MAIL_PASSWORD=');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$app = require $root . '/config/app.php';
$db = require $root . '/config/database.php';
$legal = require $root . '/config/legal.php';
$baseUrl = rtrim((string) $app['url'], '/');
$pass = 0;
$fail = 0;
$failures = [];
$suffix = 'l' . bin2hex(random_bytes(3));
$password = 'LegalBase1x';
$createdUserIds = [];
$cookieFiles = [];
$migrationName = '2026_08_22_000014_create_user_consents_table';
$termsVersion = (string) $legal['terms_version'];
$privacyVersion = (string) $legal['privacy_version'];
$ageVersion = (string) $legal['age_policy_version'];
$rulesVersion = (string) $legal['creator_rules_version'];
$contentVersion = (string) $legal['content_policy_version'];

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
    $path = tempnam(sys_get_temp_dir(), 'eronyx_legal_');
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

function createUser(PDO $pdo, string $email, string $username, string $display, string $password, array $roles, bool $verified = true): int
{
    global $createdUserIds;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $sql = $verified
        ? "INSERT INTO users (email, password_hash, status, email_verified_at) VALUES (:email, :hash, 'active', CURRENT_TIMESTAMP)"
        : "INSERT INTO users (email, password_hash, status) VALUES (:email, :hash, 'active')";
    $pdo->prepare($sql)->execute(['email' => $email, 'hash' => $hash]);
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

function trackUserId(PDO $pdo, string $email): int
{
    global $createdUserIds;
    $statement = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $statement->execute(['email' => $email]);
    $userId = (int) $statement->fetchColumn();
    if ($userId > 0 && !in_array($userId, $createdUserIds, true)) {
        $createdUserIds[] = $userId;
    }
    return $userId;
}

function consentsFor(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT consent_type, document_version, accepted_at FROM user_consents WHERE user_id = :id ORDER BY consent_type, document_version'
    );
    $statement->execute(['id' => $userId]);
    return $statement->fetchAll();
}

function consentCount(PDO $pdo, int $userId, string $type, string $version): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM user_consents WHERE user_id = :id AND consent_type = :type AND document_version = :version'
    );
    $statement->execute(['id' => $userId, 'type' => $type, 'version' => $version]);
    return (int) $statement->fetchColumn();
}

function clearRateLimits(string $root): void
{
    foreach (glob($root . '/storage/cache/rate-limits/*.json') ?: [] as $file) {
        @unlink($file);
    }
}

function registerFields(string $suffix, string $password, array $overrides = []): array
{
    return array_merge([
        'display_name' => 'Legal User',
        'username' => "u{$suffix}",
        'email' => "u{$suffix}@eronyx.test",
        'password' => $password,
        'password_confirmation' => $password,
        'accept_terms' => '1',
        'accept_privacy' => '1',
        'accept_age' => '1',
    ], $overrides);
}

function postRegister(string $baseUrl, string $root, array $fields): array
{
    clearRateLimits($root);
    $cookie = cookiePath();
    $page = http('GET', $baseUrl . '/register', ['cookie' => $cookie]);
    $fields['_csrf'] = csrfFrom($page['body']);
    $post = http('POST', $baseUrl . '/register', ['cookie' => $cookie, 'fields' => $fields]);
    return $post + ['cookie' => $cookie, 'page' => $page];
}

function runMigrate(string $root): array
{
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrate.php') . ' 2>&1', $output, $code);
    return ['code' => $code, 'output' => implode("\n", $output)];
}

function hasHref(string $html, string $path): bool
{
    return (bool) preg_match('#href="[^"]*' . preg_quote($path, '#') . '"#', $html);
}

try {
    $firstMigrate = runMigrate($root);
    $secondMigrate = runMigrate($root);
    if ($firstMigrate['code'] !== 0 || $secondMigrate['code'] !== 0) {
        throw new RuntimeException('Migration failed: ' . $firstMigrate['output'] . "\n" . $secondMigrate['output']);
    }
    $migrated = (int) $pdo->query('SELECT COUNT(*) FROM migrations WHERE migration = ' . $pdo->quote($migrationName))->fetchColumn();
    if ($migrated !== 1) {
        throw new RuntimeException('user_consents migration missing');
    }

    $legalPage = http('GET', $baseUrl . '/legal');
    $terms = http('GET', $baseUrl . '/terms');
    check(1, '/terms 200', $terms['status'] === 200 && str_contains($terms['body'], 'Términos de uso') && str_contains($terms['body'], 'revisión jurídica profesional'));
    $privacy = http('GET', $baseUrl . '/privacy');
    check(2, '/privacy 200', $privacy['status'] === 200 && str_contains($privacy['body'], 'Política de privacidad'));
    $cookies = http('GET', $baseUrl . '/cookies');
    check(3, '/cookies 200', $cookies['status'] === 200 && str_contains($cookies['body'], 'Política de cookies') && !str_contains(strtolower($cookies['body']), 'cookie banner'));
    $content = http('GET', $baseUrl . '/content-policy');
    check(4, '/content-policy 200', $content['status'] === 200);
    $rules = http('GET', $baseUrl . '/creator-rules');
    check(5, '/creator-rules 200', $rules['status'] === 200);
    $age = http('GET', $baseUrl . '/age-policy');
    check(6, '/age-policy 200', $age['status'] === 200);
    $reporting = http('GET', $baseUrl . '/reporting-policy');
    check(7, '/reporting-policy 200', $reporting['status'] === 200 && $legalPage['status'] === 200);

    $home = http('GET', $baseUrl . '/');
    $footerOk = hasHref($home['body'], '/terms')
        && hasHref($home['body'], '/privacy')
        && hasHref($home['body'], '/cookies')
        && hasHref($home['body'], '/content-policy')
        && hasHref($home['body'], '/creator-rules')
        && hasHref($home['body'], '/age-policy')
        && hasHref($home['body'], '/reporting-policy');
    check(8, 'Footer contiene links reales', $footerOk);
    check(
        9,
        'No placeholders sin href',
        !preg_match('#<span>Términos</span>#', $home['body'])
        && !preg_match('#<span>Privacidad</span>#', $home['body'])
        && !preg_match('#href=""#', $home['body'])
        && !preg_match('#href="\#"#', $home['body'])
    );

    $noTerms = postRegister($baseUrl, $root, registerFields("nt{$suffix}", $password, [
        'username' => "nt{$suffix}",
        'email' => "nt{$suffix}@eronyx.test",
        'accept_terms' => '',
    ]));
    check(10, 'Sin terms rechazado', $noTerms['status'] === 200 && str_contains($noTerms['body'], 'términos') && trackUserId($pdo, "nt{$suffix}@eronyx.test") === 0);

    $noPrivacy = postRegister($baseUrl, $root, registerFields("np{$suffix}", $password, [
        'username' => "np{$suffix}",
        'email' => "np{$suffix}@eronyx.test",
        'accept_privacy' => '',
    ]));
    check(11, 'Sin privacy rechazado', $noPrivacy['status'] === 200 && str_contains($noPrivacy['body'], 'privacidad') && trackUserId($pdo, "np{$suffix}@eronyx.test") === 0);

    $noAge = postRegister($baseUrl, $root, registerFields("na{$suffix}", $password, [
        'username' => "na{$suffix}",
        'email' => "na{$suffix}@eronyx.test",
        'accept_age' => '',
    ]));
    check(12, 'Sin age declaration rechazado', $noAge['status'] === 200 && str_contains($noAge['body'], '18') && trackUserId($pdo, "na{$suffix}@eronyx.test") === 0);

    $okReg = postRegister($baseUrl, $root, registerFields("ok{$suffix}", $password, [
        'username' => "ok{$suffix}",
        'email' => "ok{$suffix}@eronyx.test",
        'document_version' => 'spoofed-client',
        'user_id' => '999999',
    ]));
    $okId = trackUserId($pdo, "ok{$suffix}@eronyx.test");
    check(13, 'Con todo register', $okReg['status'] === 302 && $okId > 0);
    $okRows = consentsFor($pdo, $okId);
    $okTypes = array_column($okRows, 'consent_type');
    sort($okTypes);
    check(
        14,
        'user_consents rows correctas',
        $okTypes === ['age_declaration', 'privacy', 'terms']
        && consentCount($pdo, $okId, 'terms', $termsVersion) === 1
        && consentCount($pdo, $okId, 'privacy', $privacyVersion) === 1
        && consentCount($pdo, $okId, 'age_declaration', $ageVersion) === 1
    );
    check(
        15,
        'versions vienen del server',
        consentCount($pdo, $okId, 'terms', 'spoofed-client') === 0
        && consentCount($pdo, $okId, 'terms', $termsVersion) === 1
    );

    $dupId = createUser($pdo, "dup.{$suffix}@eronyx.test", "dup{$suffix}", 'Dup User', $password, ['buyer']);
    $consents = new UserConsentService($pdo);
    $consents->record($dupId, 'terms', 'client-ignored');
    $consents->record($dupId, 'terms', 'client-ignored');
    check(16, 'Mismo consent/version no duplica', consentCount($pdo, $dupId, 'terms', $termsVersion) === 1);

    $applyId = createUser($pdo, "ap.{$suffix}@eronyx.test", "ap{$suffix}", 'Apply User', $password, ['buyer']);
    $applySession = login($baseUrl, "ap.{$suffix}@eronyx.test", $password);
    $applyPage = http('GET', $baseUrl . '/account/creator/apply', ['cookie' => $applySession['cookie']]);
    $noRules = http('POST', $baseUrl . '/account/creator/apply', [
        'cookie' => $applySession['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($applyPage['body']),
            'adult_confirmation' => '1',
            'accept_content_policy' => '1',
            'document_version' => 'spoof-rules',
        ],
    ]);
    $pendingAfterNoRules = (int) $pdo->query('SELECT COUNT(*) FROM creator_profiles WHERE user_id = ' . $applyId)->fetchColumn();
    check(17, 'Creator apply sin rules bloqueado', $noRules['status'] === 200 && str_contains($noRules['body'], 'reglas') && $pendingAfterNoRules === 0);

    $applyPage2 = http('GET', $baseUrl . '/account/creator/apply', ['cookie' => $applySession['cookie']]);
    $noContent = http('POST', $baseUrl . '/account/creator/apply', [
        'cookie' => $applySession['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($applyPage2['body']),
            'adult_confirmation' => '1',
            'accept_creator_rules' => '1',
        ],
    ]);
    $pendingAfterNoContent = (int) $pdo->query('SELECT COUNT(*) FROM creator_profiles WHERE user_id = ' . $applyId)->fetchColumn();
    check(18, 'Sin content policy bloqueado', $noContent['status'] === 200 && str_contains($noContent['body'], 'contenido') && $pendingAfterNoContent === 0);

    $applyPage3 = http('GET', $baseUrl . '/account/creator/apply', ['cookie' => $applySession['cookie']]);
    $okApply = http('POST', $baseUrl . '/account/creator/apply', [
        'cookie' => $applySession['cookie'],
        'fields' => [
            '_csrf' => csrfFrom($applyPage3['body']),
            'adult_confirmation' => '1',
            'accept_creator_rules' => '1',
            'accept_content_policy' => '1',
            'document_version' => 'spoof-apply',
            'user_id' => '1',
        ],
    ]);
    $applyStatus = (string) $pdo->query('SELECT status FROM creator_profiles WHERE user_id = ' . $applyId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    check(19, 'Con todo pending', $okApply['status'] === 302 && $applyStatus === 'pending');
    check(
        20,
        'consents registrados',
        consentCount($pdo, $applyId, 'creator_rules', $rulesVersion) === 1
        && consentCount($pdo, $applyId, 'content_policy', $contentVersion) === 1
        && consentCount($pdo, $applyId, 'age_declaration', $ageVersion) === 1
        && consentCount($pdo, $applyId, 'creator_rules', 'spoof-apply') === 0
    );

    $histId = createUser($pdo, "hi.{$suffix}@eronyx.test", "hi{$suffix}", 'Hist User', $password, ['buyer']);
    $hist = new UserConsentService($pdo);
    $hist->record($histId, 'terms');
    $oldCount = consentCount($pdo, $histId, 'terms', $termsVersion);
    putenv('LEGAL_TERMS_VERSION=2026-09-01');
    $hist2 = new UserConsentService($pdo);
    check(21, 'Cambiar current version en test', $hist2->version('terms') === '2026-09-01');
    $hist2->record($histId, 'terms');
    check(22, 'Old acceptance preserved', $oldCount === 1 && consentCount($pdo, $histId, 'terms', $termsVersion) === 1);
    check(23, 'New acceptance creates second row', consentCount($pdo, $histId, 'terms', '2026-09-01') === 1);
    putenv('LEGAL_TERMS_VERSION=' . $termsVersion);

    $okSession = login($baseUrl, "ok{$suffix}@eronyx.test", $password);
    $account = http('GET', $baseUrl . '/account', ['cookie' => $okSession['cookie']]);
    $accountLegal = http('GET', $baseUrl . '/account/legal', ['cookie' => $okSession['cookie']]);
    check(
        24,
        'User ve sus consent summaries',
        $account['status'] === 200
        && $accountLegal['status'] === 200
        && str_contains($account['body'], 'terms')
        && str_contains($accountLegal['body'], $termsVersion)
        && str_contains($accountLegal['body'], 'privacy')
    );

    $otherId = createUser($pdo, "ot.{$suffix}@eronyx.test", "ot{$suffix}", 'Other User', $password, ['buyer']);
    $otherSession = login($baseUrl, "ot.{$suffix}@eronyx.test", $password);
    $otherLegal = http('GET', $baseUrl . '/account/legal?user_id=' . $okId, ['cookie' => $otherSession['cookie']]);
    check(
        25,
        'No ve otro user',
        $otherLegal['status'] === 200
        && !str_contains($otherLegal['body'], "ok{$suffix}@eronyx.test")
        && !str_contains($otherLegal['body'], 'terms · versión')
        && str_contains($otherLegal['body'], 'No hay consentimientos registrados')
    );

    $adminId = createUser($pdo, "ad.{$suffix}@eronyx.test", "ad{$suffix}", 'Admin', $password, ['buyer', 'admin']);
    $adminSession = login($baseUrl, "ad.{$suffix}@eronyx.test", $password);
    $adminDetail = http('GET', $baseUrl . '/admin/users/' . $okId, ['cookie' => $adminSession['cookie']]);
    check(
        26,
        'Admin detail muestra consent summary',
        $adminDetail['status'] === 200
        && str_contains($adminDetail['body'], 'Consentimientos')
        && str_contains($adminDetail['body'], 'terms')
        && str_contains($adminDetail['body'], $termsVersion)
        && str_contains($adminDetail['body'], 'accepted_at') === false
    );
    $buyerAdmin = http('GET', $baseUrl . '/admin/users/' . $okId, ['cookie' => $okSession['cookie']]);
    check(27, 'Buyer no admin route', $buyerAdmin['status'] === 403);
    check(
        28,
        'No IP/raw secret',
        !str_contains($adminDetail['body'], 'ip_hash')
        && !str_contains($adminDetail['body'], 'password_hash')
        && !str_contains($accountLegal['body'], 'ip_hash')
        && !str_contains($accountLegal['body'], 'REMOTE_ADDR')
    );

    check(29, 'Policy contiene minors prohibition', str_contains($content['body'], 'menores'));
    check(30, 'Policy contiene non-consensual prohibition', str_contains($content['body'], 'contenido no consentido'));
    check(31, 'Policy contiene copyright/IP rule', str_contains($content['body'], 'propiedad intelectual') || str_contains($content['body'], 'copyright'));
    check(32, 'Creator rules contienen ownership/consent', str_contains($rules['body'], 'derechos sobre el contenido') && str_contains($rules['body'], 'consentimiento'));
    check(33, 'Age policy describe 18+', str_contains($age['body'], '18 años'));
    check(34, 'Reporting policy describe report mechanism', str_contains($reporting['body'], 'reportar') && hasHref($reporting['body'], '/marketplace') && hasHref($reporting['body'], '/login'));

    $spoofReg = postRegister($baseUrl, $root, registerFields("sp{$suffix}", $password, [
        'username' => "sp{$suffix}",
        'email' => "sp{$suffix}@eronyx.test",
        'document_version' => 'hacked',
        'terms_version' => 'hacked',
        'consent_type' => 'terms',
    ]));
    $spoofId = trackUserId($pdo, "sp{$suffix}@eronyx.test");
    check(35, 'POST cannot spoof document_version', $spoofReg['status'] === 302 && $spoofId > 0 && consentCount($pdo, $spoofId, 'terms', 'hacked') === 0 && consentCount($pdo, $spoofId, 'terms', $termsVersion) === 1);

    $idSpoof = postRegister($baseUrl, $root, registerFields("id{$suffix}", $password, [
        'username' => "id{$suffix}",
        'email' => "id{$suffix}@eronyx.test",
        'user_id' => (string) $adminId,
    ]));
    $idSpoofUser = trackUserId($pdo, "id{$suffix}@eronyx.test");
    $adminConsentStolen = consentCount($pdo, $adminId, 'terms', $termsVersion);
    check(36, 'consent user_id comes from Auth/server', $idSpoof['status'] === 302 && $idSpoofUser > 0 && $idSpoofUser !== $adminId && $adminConsentStolen === 0 && consentCount($pdo, $idSpoofUser, 'terms', $termsVersion) === 1);

    $usersBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $sqli = http('GET', $baseUrl . '/terms?id=1%27%20OR%201%3D1');
    $sqliPost = postRegister($baseUrl, $root, registerFields("sq{$suffix}", $password, [
        'username' => "sq{$suffix}",
        'email' => "sq' OR 1=1 --@eronyx.test",
        'accept_terms' => "1' OR '1'='1",
    ]));
    $usersAfter = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $badType = $consents->record($dupId, "terms'; DROP TABLE user_consents;--");
    $tableStill = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_consents'")->fetchColumn();
    check(37, 'No SQLi', $sqli['status'] === 200 && $usersAfter === $usersBefore && $badType === false && $tableStill === 1 && trackUserId($pdo, "sq{$suffix}@eronyx.test") === 0);

    $csrfApply = http('POST', $baseUrl . '/account/creator/apply', [
        'cookie' => $applySession['cookie'],
        'fields' => [
            '_csrf' => 'invalid',
            'adult_confirmation' => '1',
            'accept_creator_rules' => '1',
            'accept_content_policy' => '1',
        ],
    ]);
    $csrfRegister = http('POST', $baseUrl . '/register', [
        'cookie' => cookiePath(),
        'fields' => registerFields("cf{$suffix}", $password, [
            'username' => "cf{$suffix}",
            'email' => "cf{$suffix}@eronyx.test",
            '_csrf' => 'invalid',
        ]),
    ]);
    check(
        38,
        'CSRF register/apply intacto',
        $csrfRegister['status'] === 403
        && $csrfApply['status'] === 403
        && trackUserId($pdo, "cf{$suffix}@eronyx.test") === 0
    );
} catch (Throwable $exception) {
    echo 'SUITE ERROR: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n";
    $fail++;
}

foreach ($cookieFiles as $file) {
    @unlink($file);
}
clearRateLimits($root);
MailService::clear();
putenv('LEGAL_TERMS_VERSION=' . $termsVersion);

try {
    $ids = implode(',', array_map('intval', $createdUserIds)) ?: '0';
    $pdo->exec("DELETE FROM notifications WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM audit_logs WHERE actor_user_id IN ({$ids})");
    $pdo->exec("DELETE FROM email_verification_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM password_reset_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM age_verifications WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM creator_profiles WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM user_consents WHERE user_id IN ({$ids})");
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
