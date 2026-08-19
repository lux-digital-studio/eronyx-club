<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;
use App\Repositories\AuditLogRepository;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\UserRepository;
use App\Validators\ChangePasswordValidator;
use App\Validators\ResetPasswordValidator;
use RuntimeException;
use Throwable;

final class AccountSecurityService
{
    public const GENERIC_FORGOT_MESSAGE = 'Si existe una cuenta asociada a ese email, se ha generado una solicitud de recuperación.';

    private \PDO $pdo;
    private UserRepository $users;
    private PasswordResetTokenRepository $tokens;
    private AuditLogRepository $audit;
    private ChangePasswordValidator $changeValidator;
    private ResetPasswordValidator $resetValidator;

    public function __construct(
        private readonly Session $session,
        ?\PDO $pdo = null,
        ?UserRepository $users = null,
        ?PasswordResetTokenRepository $tokens = null,
        ?AuditLogRepository $audit = null
    ) {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->users = $users ?? new UserRepository($this->pdo);
        $this->tokens = $tokens ?? new PasswordResetTokenRepository($this->pdo);
        $this->audit = $audit ?? new AuditLogRepository($this->pdo);
        $this->changeValidator = new ChangePasswordValidator();
        $this->resetValidator = new ResetPasswordValidator();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function changePassword(int $userId, array $input): array
    {
        $validation = $this->changeValidator->validate($input);

        if (!$validation['valid']) {
            return ['ok' => false, 'errors' => $validation['errors']];
        }

        $user = $this->users->findAuthById($userId);

        if ($user === null || $user['deleted_at'] !== null || $user['status'] !== 'active') {
            return ['ok' => false, 'errors' => ['current_password' => 'La contraseña actual no es correcta.']];
        }

        if (!password_verify($validation['data']['current_password'], $user['password_hash'])) {
            return ['ok' => false, 'errors' => ['current_password' => 'La contraseña actual no es correcta.']];
        }

        $passwordHash = password_hash($validation['data']['new_password'], PASSWORD_DEFAULT);

        if (!is_string($passwordHash)) {
            throw new RuntimeException('Unable to securely hash password.');
        }

        try {
            $this->pdo->beginTransaction();
            $this->users->updatePasswordHash($userId, $passwordHash);
            $this->tokens->invalidateActiveForUser($userId);
            $this->tokens->cleanupExpiredForUser($userId);
            $version = $this->users->incrementSessionVersion($userId);
            $this->audit->record($userId, 'password_changed', 'user', $userId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $this->refreshCurrentSession($userId, $version);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Always returns the generic message. Local/test may include a reset URL.
     *
     * @return array{message: string, reset_url: string|null}
     */
    public function requestPasswordReset(string $email, string $clientIp, bool $exposeResetUrl): array
    {
        $email = strtolower(trim($email));
        $ipHash = $this->hashValue($clientIp);
        $user = $this->users->findByEmail($email);

        if ($user === null || ($user['deleted_at'] ?? null) !== null || ($user['status'] ?? '') !== 'active') {
            $this->dummyTokenWork();

            return [
                'message' => self::GENERIC_FORGOT_MESSAGE,
                'reset_url' => null,
            ];
        }

        $userId = (int) $user['id'];
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = $this->hashValue($rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + PasswordResetTokenRepository::TTL_SECONDS);

        try {
            $this->pdo->beginTransaction();
            $this->tokens->cleanupExpiredForUser($userId);
            $this->tokens->invalidateActiveForUser($userId);
            $this->tokens->create($userId, $tokenHash, $expiresAt, $ipHash);
            $this->audit->record($userId, 'password_reset_requested', 'user', $userId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return [
            'message' => self::GENERIC_FORGOT_MESSAGE,
            'reset_url' => $exposeResetUrl ? $this->resetUrl($rawToken) : null,
        ];
    }

    public function tokenIsValid(string $rawToken): bool
    {
        $hash = $this->normalizeAndHashToken($rawToken);

        return $hash !== null && $this->tokens->findValidByTokenHash($hash) !== null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, errors: array<string, string>, consumed: bool}
     */
    public function resetPassword(string $rawToken, array $input): array
    {
        $validation = $this->resetValidator->validate($input);

        if (!$validation['valid']) {
            return ['ok' => false, 'errors' => $validation['errors'], 'consumed' => false];
        }

        $tokenHash = $this->normalizeAndHashToken($rawToken);

        if ($tokenHash === null) {
            return ['ok' => false, 'errors' => ['token' => 'invalid'], 'consumed' => false];
        }

        $passwordHash = password_hash($validation['data']['new_password'], PASSWORD_DEFAULT);

        if (!is_string($passwordHash)) {
            throw new RuntimeException('Unable to securely hash password.');
        }

        try {
            $this->pdo->beginTransaction();
            $token = $this->tokens->lockValidByTokenHash($tokenHash);

            if ($token === null || !$this->tokens->consume($token['id'], $token['user_id'])) {
                $this->pdo->rollBack();

                return ['ok' => false, 'errors' => ['token' => 'invalid'], 'consumed' => false];
            }

            $user = $this->users->findAuthById($token['user_id']);

            if ($user === null || $user['deleted_at'] !== null || $user['status'] !== 'active') {
                $this->pdo->rollBack();

                return ['ok' => false, 'errors' => ['token' => 'invalid'], 'consumed' => false];
            }

            $this->users->updatePasswordHash($token['user_id'], $passwordHash);
            $this->tokens->invalidateActiveForUser($token['user_id']);
            $this->tokens->cleanupExpiredForUser($token['user_id']);
            $this->users->incrementSessionVersion($token['user_id']);
            $this->audit->record($token['user_id'], 'password_reset_completed', 'user', $token['user_id']);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return ['ok' => true, 'errors' => [], 'consumed' => true];
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    private function refreshCurrentSession(int $userId, int $sessionVersion): void
    {
        $this->session->regenerate();
        $this->session->put('auth_user_id', $userId);
        $this->session->put('auth_session_version', $sessionVersion);
    }

    private function dummyTokenWork(): void
    {
        hash('sha256', random_bytes(32));
    }

    private function hashValue(string $value): string
    {
        return hash('sha256', $value);
    }

    private function normalizeAndHashToken(string $rawToken): ?string
    {
        $rawToken = trim($rawToken);

        if (preg_match('/\A[a-f0-9]{64}\z/', $rawToken) !== 1) {
            return null;
        }

        return self::hashToken($rawToken);
    }

    private function resetUrl(string $rawToken): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/reset-password/' . $rawToken;
    }
}
