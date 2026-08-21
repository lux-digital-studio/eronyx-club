<?php

declare(strict_types=1);

use App\Repositories\AgeVerificationRepository;
use App\Services\AgeVerificationService;
use App\Services\CreatorApplicationService;
use App\Services\MailService;
use App\Services\Verification\TestAgeVerificationProvider;

putenv('MAIL_MAILER=array');
putenv('MAIL_PASSWORD=');
putenv('VERIFICATION_MODE=manual_review');
putenv('VERIFICATION_PROVIDER=');
putenv('VERIFICATION_REQUIRE_FOR_CREATOR=true');
putenv('VERIFICATION_SESSION_TTL=86400');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$app = require $root . '/config/app.php';
$db = require $root . '/config/database.php';
$baseUrl = rtrim((string) $app['url'], '/');
$pass = 0;
$fail = 0;
$failures = [];
$suffix = 'v' . bin2hex(random_bytes(3));
$password = 'AgeVerif1x';
$createdUserIds = [];
$cookieFiles = [];
$migrationName = '2026_08_22_000013_extend_age_verifications_for_provider';

MailService::clear();
TestAgeVerificationProvider::reset();

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
    $path = tempnam(sys_get_temp_dir(), 'eronyx_kyc_');
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
    $pdo->prepare("INSERT INTO users (email, password_hash, status, email_verified_at) VALUES (:email, :hash, 'active', CURRENT_TIMESTAMP)")
        ->execute(['email' => $email, 'hash' => $hash]);
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

function hasCreatorRole(PDO $pdo, int $userId): bool
{
    $statement = $pdo->prepare(
        "SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id AND r.name = 'creator' LIMIT 1"
    );
    $statement->execute(['id' => $userId]);
    return $statement->fetchColumn() !== false;
}

function creatorStatus(PDO $pdo, int $userId): string
{
    $statement = $pdo->prepare('SELECT status FROM creator_profiles WHERE user_id = :id ORDER BY id DESC LIMIT 1');
    $statement->execute(['id' => $userId]);
    $status = $statement->fetchColumn();
    return is_string($status) ? $status : '';
}

function ageRows(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare('SELECT id, status, method, metadata_json, provider_reference FROM age_verifications WHERE user_id = :id ORDER BY id ASC');
    $statement->execute(['id' => $userId]);
    return $statement->fetchAll();
}

function runMigrate(string $root): array
{
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrate.php') . ' 2>&1', $output, $code);
    return ['code' => $code, 'output' => implode("\n", $output)];
}

try {
    $firstMigrate = runMigrate($root);
    $secondMigrate = runMigrate($root);
    $migrated = (int) $pdo->query('SELECT COUNT(*) FROM migrations WHERE migration = ' . $pdo->quote($migrationName))->fetchColumn();
    check(1, 'Migration aplica', $firstMigrate['code'] === 0 && $migrated === 1);
    check(2, 'Second migrate idempotent', $secondMigrate['code'] === 0 && str_contains($secondMigrate['output'], 'Migrations complete.'));

    $columns = $pdo->query('SHOW COLUMNS FROM age_verifications')->fetchAll(PDO::FETCH_COLUMN);
    $columnHaystack = strtolower(implode(' ', $columns));
    check(
        3,
        'No sensitive document columns',
        !str_contains($columnHaystack, 'document_number')
        && !str_contains($columnHaystack, 'passport')
        && !str_contains($columnHaystack, 'dni')
        && !str_contains($columnHaystack, 'selfie')
        && !str_contains($columnHaystack, 'biometric')
        && !str_contains($columnHaystack, 'date_of_birth')
        && !in_array('dob', $columns, true)
    );
    $fk = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'age_verifications' AND constraint_name = 'age_verifications_reviewed_by_user_id_foreign'"
    )->fetchColumn();
    check(4, 'FK/index correctos', $fk === 1 && in_array('reviewed_by_user_id', $columns, true) && in_array('metadata_json', $columns, true));

    $buyerId = createUser($pdo, "buy.{$suffix}@eronyx.test", "buy{$suffix}", 'Buyer', $password, ['buyer']);
    $buyerB = createUser($pdo, "buyb.{$suffix}@eronyx.test", "buyb{$suffix}", 'Buyer B', $password, ['buyer']);
    $modId = createUser($pdo, "mod.{$suffix}@eronyx.test", "mod{$suffix}", 'Mod', $password, ['buyer', 'moderator']);
    $adminId = createUser($pdo, "adm.{$suffix}@eronyx.test", "adm{$suffix}", 'Admin', $password, ['buyer', 'admin']);
    $apps = new CreatorApplicationService($pdo);
    $age = new AgeVerificationService($pdo);

    $apps->apply($buyerId);
    $application = $apps->findForUser($buyerId);
    $rows = ageRows($pdo, $buyerId);
    check(5, 'Verified email user applies', is_array($application) && (int) $application['user_id'] === $buyerId);
    check(6, 'creator_profile pending', creatorStatus($pdo, $buyerId) === 'pending');
    check(7, 'age_verification pending', count($rows) === 1 && $rows[0]['status'] === 'pending' && $rows[0]['method'] === 'manual_review');
    check(8, 'no creator role', !hasCreatorRole($pdo, $buyerId));

    $blocked = false;
    try {
        $apps->approve((int) $application['id'], $modId);
    } catch (RuntimeException $exception) {
        $blocked = $exception->getMessage() === 'verification_required';
    }
    check(9, 'Approve with pending verification blocked', $blocked);
    check(10, 'No creator role after blocked approve', !hasCreatorRole($pdo, $buyerId));
    check(11, 'creator_profile remains pending', creatorStatus($pdo, $buyerId) === 'pending');

    $verified = $age->reviewManual($buyerId, $modId, true);
    $rows = ageRows($pdo, $buyerId);
    $auditVerified = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'age_verification_verified' AND entity_id = " . (int) $rows[0]['id'])->fetchColumn();
    check(12, 'Moderator verifies', $verified);
    check(13, 'age_verification verified', $rows[0]['status'] === 'verified');
    check(14, 'audit created', $auditVerified === 1);
    $approved = $apps->approve((int) $application['id'], $modId);
    check(15, 'Then creator approve succeeds', $approved && creatorStatus($pdo, $buyerId) === 'active');
    $roleCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = " . (int) $buyerId . " AND r.name = 'creator'"
    )->fetchColumn();
    check(16, 'role creator exactly once', $roleCount === 1);

    $rejectUser = createUser($pdo, "rej.{$suffix}@eronyx.test", "rej{$suffix}", 'Rejectee', $password, ['buyer']);
    $apps->apply($rejectUser);
    $rejectApp = $apps->findForUser($rejectUser);
    $ageRejected = $age->reviewManual($rejectUser, $modId, false, 'age_not_confirmed');
    $rejectBlocked = false;
    try {
        $apps->approve((int) $rejectApp['id'], $modId);
    } catch (RuntimeException $exception) {
        $rejectBlocked = $exception->getMessage() === 'verification_required';
    }
    check(17, 'Manual verification rejected', $ageRejected && ageRows($pdo, $rejectUser)[0]['status'] === 'rejected');
    check(18, 'Creator approve blocked after reject', $rejectBlocked);
    check(19, 'No role creator after reject verify', !hasCreatorRole($pdo, $rejectUser));

    $apps->reject((int) $rejectApp['id'], $modId);
    $beforeHistory = count(ageRows($pdo, $rejectUser));
    $apps->apply($rejectUser);
    $afterRows = ageRows($pdo, $rejectUser);
    check(20, 'Rejected creator reapply', creatorStatus($pdo, $rejectUser) === 'pending');
    check(21, 'New verification row', count($afterRows) === $beforeHistory + 1 && $afterRows[count($afterRows) - 1]['status'] === 'pending');
    check(22, 'Old history preserved', $afterRows[0]['status'] === 'rejected');

    putenv('VERIFICATION_MODE=provider');
    putenv('VERIFICATION_PROVIDER=test');
    $providerUser = createUser($pdo, "prv.{$suffix}@eronyx.test", "prv{$suffix}", 'Provider', $password, ['buyer']);
    $providerAge = new AgeVerificationService($pdo);
    $pendingId = $providerAge->startVerification($providerUser, $providerUser);
    $pendingRow = (new AgeVerificationRepository($pdo))->findById($pendingId);
    check(23, 'Provider pending', is_array($pendingRow) && $pendingRow['status'] === 'pending' && ($pendingRow['provider'] ?? '') === 'test');
    check(24, 'Provider verified', $providerAge->markProviderResult($pendingId, 'verified') && ageRows($pdo, $providerUser)[0]['status'] === 'verified');

    $providerUser2 = createUser($pdo, "prv2.{$suffix}@eronyx.test", "prv2{$suffix}", 'Provider2', $password, ['buyer']);
    $rejId = $providerAge->startVerification($providerUser2, $providerUser2);
    check(25, 'Provider rejected', $providerAge->markProviderResult($rejId, 'rejected') && ageRows($pdo, $providerUser2)[0]['status'] === 'rejected');
    $providerUser3 = createUser($pdo, "prv3.{$suffix}@eronyx.test", "prv3{$suffix}", 'Provider3', $password, ['buyer']);
    $expId = $providerAge->startVerification($providerUser3, $providerUser3);
    check(26, 'Provider expired', $providerAge->markProviderResult($expId, 'expired') && ageRows($pdo, $providerUser3)[0]['status'] === 'expired');

    putenv('VERIFICATION_MODE=manual_review');
    putenv('VERIFICATION_PROVIDER=');
    $requireUser = createUser($pdo, "req.{$suffix}@eronyx.test", "req{$suffix}", 'Require', $password, ['buyer']);
    $appsRequire = new CreatorApplicationService($pdo);
    $appsRequire->apply($requireUser);
    $reqApp = $appsRequire->findForUser($requireUser);
    $requireBlocked = false;
    try {
        $appsRequire->approve((int) $reqApp['id'], $modId);
    } catch (RuntimeException $exception) {
        $requireBlocked = $exception->getMessage() === 'verification_required';
    }
    check(27, 'require verification=true blocks', $requireBlocked && !hasCreatorRole($pdo, $requireUser));

    putenv('VERIFICATION_MODE=self_declaration');
    putenv('VERIFICATION_REQUIRE_FOR_CREATOR=false');
    $legacyUser = createUser($pdo, "leg.{$suffix}@eronyx.test", "leg{$suffix}", 'Legacy', $password, ['buyer']);
    $legacyApps = new CreatorApplicationService($pdo);
    $legacyApps->apply($legacyUser);
    $legacyApp = $legacyApps->findForUser($legacyUser);
    $legacyOk = $legacyApps->approve((int) $legacyApp['id'], $modId);
    check(28, 'require=false allows legacy self-declaration', $legacyOk && hasCreatorRole($pdo, $legacyUser) && ageRows($pdo, $legacyUser)[0]['status'] === 'verified');
    putenv('VERIFICATION_MODE=manual_review');
    putenv('VERIFICATION_REQUIRE_FOR_CREATOR=true');

    $sessionA = login($baseUrl, "buy.{$suffix}@eronyx.test", $password);
    $statusA = http('GET', $baseUrl . '/account/creator/status', ['cookie' => $sessionA['cookie']]);
    $adminAsA = http('GET', $baseUrl . '/admin/creators/' . $buyerB, ['cookie' => $sessionA['cookie']]);
    check(29, 'User A cannot see user B verification', $statusA['status'] === 200 && !str_contains($statusA['body'], "buyb{$suffix}") && $adminAsA['status'] === 403);

    $reviewAsBuyer = http('POST', $baseUrl . '/moderator/creator-applications/' . (int) $rejectApp['id'] . '/verify-age', [
        'cookie' => $sessionA['cookie'],
        'fields' => ['_csrf' => csrfFrom($statusA['body'])],
    ]);
    check(30, 'Buyer cannot moderator review', $reviewAsBuyer['status'] === 403);

    $adminSession = login($baseUrl, "adm.{$suffix}@eronyx.test", $password);
    $pendingUser = createUser($pdo, "pend.{$suffix}@eronyx.test", "pend{$suffix}", 'Pending', $password, ['buyer']);
    (new CreatorApplicationService($pdo))->apply($pendingUser);
    $pendingApp = (new CreatorApplicationService($pdo))->findForUser($pendingUser);
    $adminPage = http('GET', $baseUrl . '/admin', ['cookie' => $adminSession['cookie']]);
    $adminReview = http('POST', $baseUrl . '/moderator/creator-applications/' . (int) $pendingApp['id'] . '/verify-age', [
        'cookie' => $adminSession['cookie'],
        'fields' => ['_csrf' => csrfFrom($adminPage['body'])],
    ]);
    check(31, 'Admin-only cannot manual review', $adminReview['status'] === 403 && ageRows($pdo, $pendingUser)[0]['status'] === 'pending');

    $modSession = login($baseUrl, "mod.{$suffix}@eronyx.test", $password);
    $modShow = http('GET', $baseUrl . '/moderator/creator-applications/' . (int) $pendingApp['id'], ['cookie' => $modSession['cookie']]);
    $modReview = http('POST', $baseUrl . '/moderator/creator-applications/' . (int) $pendingApp['id'] . '/verify-age', [
        'cookie' => $modSession['cookie'],
        'fields' => ['_csrf' => csrfFrom($modShow['body'])],
    ]);
    check(32, 'Moderator can manual review', $modReview['status'] === 302 && ageRows($pdo, $pendingUser)[0]['status'] === 'verified');

    check(33, 'No document_number column', !in_array('document_number', $columns, true));
    check(34, 'No passport/DNI field', !str_contains($columnHaystack, 'passport') && !str_contains($columnHaystack, 'dni'));
    check(35, 'No biometric data', !str_contains($columnHaystack, 'biometric') && !str_contains($columnHaystack, 'selfie'));
    $secretCols = array_filter($columns, static fn (string $col): bool => str_contains(strtolower($col), 'secret') || str_contains(strtolower($col), 'api_key'));
    check(36, 'No provider secret in DB', $secretCols === []);
    $metaOk = true;
    foreach (ageRows($pdo, $buyerId) as $row) {
        if (!is_string($row['metadata_json'] ?? null) || $row['metadata_json'] === '') {
            continue;
        }
        $decoded = json_decode((string) $row['metadata_json'], true);
        if (!is_array($decoded)) {
            $metaOk = false;
            break;
        }
        foreach (array_keys($decoded) as $key) {
            if (!in_array($key, AgeVerificationRepository::METADATA_KEYS, true)) {
                $metaOk = false;
            }
        }
    }
    check(37, 'metadata contains only allowlisted keys', $metaOk);

    check(38, 'Account shows verification status', $statusA['status'] === 200 && str_contains($statusA['body'], 'Verificación de edad'));
    $queue = http('GET', $baseUrl . '/moderator/creator-applications', ['cookie' => $modSession['cookie']]);
    check(39, 'Moderator queue shows status', $queue['status'] === 200 && (str_contains($queue['body'], 'Revisión manual') || str_contains($queue['body'], 'Pendiente') || str_contains($queue['body'], 'Verificada')));
    $adminDetail = http('GET', $baseUrl . '/admin/creators/' . $buyerId, ['cookie' => $adminSession['cookie']]);
    $ref = (string) (ageRows($pdo, $buyerId)[0]['provider_reference'] ?? '');
    check(
        40,
        'Admin detail safe',
        $adminDetail['status'] === 200
        && str_contains($adminDetail['body'], 'Verificación de edad')
        && !str_contains($adminDetail['body'], 'metadata_json')
        && ($ref === '' || !str_contains($adminDetail['body'], $ref))
        && !str_contains($adminDetail['body'], 'document_number')
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
TestAgeVerificationProvider::reset();
putenv('VERIFICATION_MODE=manual_review');
putenv('VERIFICATION_PROVIDER=');
putenv('VERIFICATION_REQUIRE_FOR_CREATOR=true');

try {
    $ids = implode(',', array_map('intval', $createdUserIds)) ?: '0';
    $avIds = $pdo->query("SELECT id FROM age_verifications WHERE user_id IN ({$ids})")->fetchAll(PDO::FETCH_COLUMN);
    $avList = $avIds !== [] ? implode(',', array_map('intval', $avIds)) : '0';
    $cpIds = $pdo->query("SELECT id FROM creator_profiles WHERE user_id IN ({$ids})")->fetchAll(PDO::FETCH_COLUMN);
    $cpList = $cpIds !== [] ? implode(',', array_map('intval', $cpIds)) : '0';
    $pdo->exec("DELETE FROM notifications WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM audit_logs WHERE actor_user_id IN ({$ids}) OR (entity_type = 'age_verification' AND entity_id IN ({$avList})) OR (entity_type = 'creator_application' AND entity_id IN ({$cpList}))");
    $pdo->exec("DELETE FROM email_verification_tokens WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM password_reset_tokens WHERE user_id IN ({$ids})");
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
