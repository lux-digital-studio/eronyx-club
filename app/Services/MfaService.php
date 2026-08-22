<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;
use App\Repositories\AuditLogRepository;
use App\Repositories\MfaRepository;
use App\Repositories\UserRepository;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use OTPHP\TOTP;
use RuntimeException;
use Throwable;

final class MfaService
{
    public const INVALID_CODE = 'Código no válido.';

    private \PDO $pdo;
    private MfaRepository $mfa;
    private UserRepository $users;
    private AuditLogRepository $audit;
    private MfaCrypto $crypto;
    private TransactionalMailService $mail;
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed>|null $config */
    public function __construct(
        private readonly Session $session,
        ?\PDO $pdo = null,
        ?MfaRepository $mfa = null,
        ?UserRepository $users = null,
        ?AuditLogRepository $audit = null,
        ?MfaCrypto $crypto = null,
        ?TransactionalMailService $mail = null,
        ?array $config = null
    ) {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->mfa = $mfa ?? new MfaRepository($this->pdo);
        $this->users = $users ?? new UserRepository($this->pdo);
        $this->audit = $audit ?? new AuditLogRepository($this->pdo);
        $this->config = $config ?? require dirname(__DIR__, 2) . '/config/mfa.php';
        $this->crypto = $crypto ?? new MfaCrypto($this->config);
        $this->mail = $mail ?? new TransactionalMailService(null, null, $this->users, $this->pdo);
    }

    public function isEnabled(int $userId): bool
    {
        return $this->mfa->isEnabled($userId);
    }

    /** @return array{status: string, unused_codes: int} */
    public function status(int $userId): array
    {
        $row = $this->mfa->findForUser($userId);
        $enabled = is_array($row) && $row['status'] === 'enabled';

        return [
            'status' => $enabled ? 'enabled' : 'disabled',
            'unused_codes' => $enabled ? $this->mfa->unusedRecoveryCount($userId) : 0,
        ];
    }

    /**
     * @return array{ok: bool, secret?: string, otpauth_uri?: string, qr_data_uri?: string, error?: string}
     */
    public function beginSetup(int $userId): array
    {
        if (!$this->users->isEmailVerified($userId)) {
            return ['ok' => false, 'error' => 'unverified'];
        }

        if ($this->mfa->isEnabled($userId)) {
            return ['ok' => false, 'error' => 'already_enabled'];
        }

        try {
            $secret = $this->generateSecret();
            $encrypted = $this->crypto->encrypt($secret);
            $this->mfa->upsertPending($userId, $encrypted);
            $this->audit->record($userId, 'mfa_setup_started', 'user', $userId);
            $uri = $this->buildOtpAuthUri($userId, $secret);

            return [
                'ok' => true,
                'secret' => $secret,
                'otpauth_uri' => $uri,
                'qr_data_uri' => $this->qrDataUri($uri),
            ];
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'setup_failed'];
        }
    }

    /**
     * @return array{ok: bool, codes?: list<string>, error?: string}
     */
    public function confirmSetup(int $userId, string $code): array
    {
        $row = $this->mfa->findForUser($userId);

        if ($row === null || $row['status'] !== 'pending') {
            return ['ok' => false, 'error' => self::INVALID_CODE];
        }

        if (!$this->verifyTotpAgainstRow($row, $code)) {
            return ['ok' => false, 'error' => self::INVALID_CODE];
        }

        $codes = [];

        try {
            $this->pdo->beginTransaction();

            if (!$this->mfa->enable($userId)) {
                $this->pdo->rollBack();

                return ['ok' => false, 'error' => self::INVALID_CODE];
            }

            $codes = $this->generateRecoveryCodes();
            $this->mfa->replaceRecoveryCodes($userId, $this->hashCodes($codes));
            $version = $this->users->incrementSessionVersion($userId);
            $this->audit->record($userId, 'mfa_enabled', 'user', $userId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $this->refreshCurrentSession($userId, $version);

        try {
            $this->mail->sendMfaEnabled($userId);
        } catch (Throwable) {
        }

        return ['ok' => true, 'codes' => $codes];
    }

    public function verifyLoginCode(int $userId, string $code): bool
    {
        $row = $this->mfa->findForUser($userId);

        return is_array($row) && $row['status'] === 'enabled' && $this->verifyTotpAgainstRow($row, $code);
    }

    public function useRecoveryCode(int $userId, string $rawCode): bool
    {
        $normalized = $this->normalizeRecoveryCode($rawCode);

        if ($normalized === null) {
            return false;
        }

        $consumed = $this->mfa->consumeRecoveryCode($userId, $this->crypto->hmac($normalized));

        if ($consumed) {
            $this->audit->record($userId, 'mfa_recovery_code_used', 'user', $userId);
        }

        return $consumed;
    }

    /**
     * @return array{ok: bool, codes?: list<string>, error?: string}
     */
    public function regenerateRecoveryCodes(int $userId, string $password, string $mfaValue): array
    {
        if (!$this->mfa->isEnabled($userId)) {
            return ['ok' => false, 'error' => 'not_enabled'];
        }

        if (!$this->verifyCurrentPassword($userId, $password) || !$this->verifyEnabledFactor($userId, $mfaValue)) {
            return ['ok' => false, 'error' => self::INVALID_CODE];
        }

        $codes = $this->generateRecoveryCodes();

        try {
            $this->pdo->beginTransaction();
            $this->mfa->replaceRecoveryCodes($userId, $this->hashCodes($codes));
            $version = $this->users->incrementSessionVersion($userId);
            $this->audit->record($userId, 'mfa_recovery_codes_regenerated', 'user', $userId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $this->refreshCurrentSession($userId, $version);

        return ['ok' => true, 'codes' => $codes];
    }

    /** @return array{ok: bool, error?: string} */
    public function disable(int $userId, string $password, string $mfaValue): array
    {
        if (!$this->mfa->isEnabled($userId)) {
            return ['ok' => false, 'error' => 'not_enabled'];
        }

        if (!$this->verifyCurrentPassword($userId, $password) || !$this->verifyEnabledFactor($userId, $mfaValue)) {
            return ['ok' => false, 'error' => self::INVALID_CODE];
        }

        try {
            $this->pdo->beginTransaction();
            $this->mfa->invalidateRecoveryCodes($userId);
            $this->mfa->deleteForUser($userId);
            $version = $this->users->incrementSessionVersion($userId);
            $this->audit->record($userId, 'mfa_disabled', 'user', $userId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $this->refreshCurrentSession($userId, $version);

        try {
            $this->mail->sendMfaDisabled($userId);
        } catch (Throwable) {
        }

        return ['ok' => true];
    }

    public function generateSecret(): string
    {
        return TOTP::generate()->getSecret();
    }

    public function buildOtpAuthUri(int $userId, string $secret): string
    {
        $user = $this->users->findMailRecipient($userId);
        $label = is_array($user) && $user['username'] !== '' ? $user['username'] : ('user' . $userId);
        $totp = $this->totpFromSecret($secret);
        $totp->setLabel($label);
        $totp->setIssuer((string) $this->config['issuer']);

        return $totp->getProvisioningUri();
    }

    public function flashRecoveryCodes(array $codes): void
    {
        $this->session->put('mfa_recovery_codes', $codes);
    }

    /** @return list<string> */
    public function takeRecoveryCodes(): array
    {
        $codes = $this->session->get('mfa_recovery_codes');
        $this->session->remove('mfa_recovery_codes');

        if (!is_array($codes)) {
            return [];
        }

        $out = [];

        foreach ($codes as $code) {
            if (is_string($code) && $code !== '') {
                $out[] = $code;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        $count = max(1, (int) ($this->config['recovery_code_count'] ?? 10));
        $groups = max(1, (int) ($this->config['recovery_code_groups'] ?? 3));
        $length = max(3, (int) ($this->config['recovery_code_group_length'] ?? 4));
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $alphaLen = strlen($alphabet);
        $codes = [];

        while (count($codes) < $count) {
            $parts = [];

            for ($g = 0; $g < $groups; $g++) {
                $chunk = '';
                $bytes = random_bytes($length);

                for ($i = 0; $i < $length; $i++) {
                    $chunk .= $alphabet[ord($bytes[$i]) % $alphaLen];
                }

                $parts[] = $chunk;
            }

            $codes[] = implode('-', $parts);
        }

        return $codes;
    }

    public function normalizeRecoveryCode(string $raw): ?string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($raw))) ?? '');

        $expected = max(1, (int) ($this->config['recovery_code_groups'] ?? 3))
            * max(3, (int) ($this->config['recovery_code_group_length'] ?? 4));

        return strlen($normalized) === $expected ? $normalized : null;
    }

    /** @param array{secret_encrypted: string} $row */
    private function verifyTotpAgainstRow(array $row, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (preg_match('/\A\d{6}\z/', $code) !== 1) {
            return false;
        }

        try {
            $secret = $this->crypto->decrypt($row['secret_encrypted']);
        } catch (Throwable) {
            return false;
        }

        return $this->totpFromSecret($secret)->verify($code, null, max(0, (int) ($this->config['window'] ?? 1)));
    }

    private function totpFromSecret(string $secret): TOTP
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setPeriod(max(1, (int) ($this->config['period'] ?? 30)));
        $totp->setDigits(max(6, (int) ($this->config['digits'] ?? 6)));
        $totp->setDigest((string) ($this->config['algorithm'] ?? 'sha1'));

        return $totp;
    }

    private function qrDataUri(string $otpauthUri): string
    {
        $renderer = new ImageRenderer(new RendererStyle(192), new SvgImageBackEnd());
        $svg = (new Writer($renderer))->writeString($otpauthUri);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function verifyCurrentPassword(int $userId, string $password): bool
    {
        $user = $this->users->findAuthById($userId);

        return is_array($user)
            && $user['status'] === 'active'
            && $user['deleted_at'] === null
            && password_verify($password, $user['password_hash']);
    }

    private function verifyEnabledFactor(int $userId, string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/\A\d{6}\z/', preg_replace('/\D/', '', $trimmed) ?? '') === 1) {
            return $this->verifyLoginCode($userId, $trimmed);
        }

        return $this->useRecoveryCode($userId, $trimmed);
    }

    /** @param list<string> $codes @return list<string> */
    private function hashCodes(array $codes): array
    {
        $hashes = [];

        foreach ($codes as $code) {
            $normalized = $this->normalizeRecoveryCode($code);

            if ($normalized !== null) {
                $hashes[] = $this->crypto->hmac($normalized);
            }
        }

        return $hashes;
    }

    private function refreshCurrentSession(int $userId, int $sessionVersion): void
    {
        $this->session->regenerate();
        $this->session->put('auth_user_id', $userId);
        $this->session->put('auth_session_version', $sessionVersion);
    }
}
