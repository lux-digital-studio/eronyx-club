<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\EmailVerificationTokenRepository;
use App\Repositories\UserRepository;
use Throwable;

final class EmailVerificationService
{
    public const TTL_SECONDS = EmailVerificationTokenRepository::TTL_SECONDS;

    private \PDO $pdo;
    private UserRepository $users;
    private EmailVerificationTokenRepository $tokens;
    private TransactionalMailService $mail;
    private AuditLogRepository $audit;

    public function __construct(
        ?\PDO $pdo = null,
        ?UserRepository $users = null,
        ?EmailVerificationTokenRepository $tokens = null,
        ?TransactionalMailService $mail = null,
        ?AuditLogRepository $audit = null
    ) {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->users = $users ?? new UserRepository($this->pdo);
        $this->tokens = $tokens ?? new EmailVerificationTokenRepository($this->pdo);
        $this->mail = $mail ?? new TransactionalMailService(null, null, $this->users, $this->pdo);
        $this->audit = $audit ?? new AuditLogRepository($this->pdo);
    }

    public function isVerified(int $userId): bool
    {
        return $this->users->isEmailVerified($userId);
    }

    public function issueForUser(int $userId, string $clientIp): bool
    {
        $user = $this->users->findAuthById($userId);

        if ($user === null || $user['deleted_at'] !== null || $user['status'] !== 'active') {
            return false;
        }

        if ($user['email_verified_at'] !== null) {
            return true;
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = self::hashToken($rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);
        $tokenId = 0;

        try {
            $this->pdo->beginTransaction();
            $this->tokens->cleanupExpiredForUser($userId);
            $this->tokens->invalidateActiveForUser($userId);
            $tokenId = $this->tokens->create($userId, $tokenHash, $expiresAt, $this->hashValue($clientIp));
            $this->audit->record($userId, 'email_verification_sent', 'user', $userId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $delivered = $this->mail->sendEmailVerification($userId, $this->verificationUrl($rawToken));

        if (!$delivered) {
            $this->tokens->invalidateById($tokenId, $userId);

            return false;
        }

        return true;
    }

    /**
     * @return array{ok: bool, already: bool}
     */
    public function verifyToken(string $rawToken): array
    {
        $tokenHash = $this->normalizeAndHashToken($rawToken);

        if ($tokenHash === null) {
            return ['ok' => false, 'already' => false];
        }

        try {
            $this->pdo->beginTransaction();
            $token = $this->tokens->lockValidByTokenHash($tokenHash);

            if ($token === null || !$this->tokens->consume($token['id'], $token['user_id'])) {
                $this->pdo->rollBack();

                return ['ok' => false, 'already' => false];
            }

            $user = $this->users->findAuthById($token['user_id']);

            if ($user === null || $user['deleted_at'] !== null) {
                $this->pdo->rollBack();

                return ['ok' => false, 'already' => false];
            }

            $already = $user['email_verified_at'] !== null;
            $this->users->markEmailVerified($token['user_id']);
            $this->audit->record($token['user_id'], 'email_verified', 'user', $token['user_id']);
            $this->pdo->commit();

            return ['ok' => true, 'already' => $already];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return array{ok: bool, already_verified: bool, mailed: bool}
     */
    public function resend(int $userId, string $clientIp): array
    {
        if ($this->users->isEmailVerified($userId)) {
            return ['ok' => true, 'already_verified' => true, 'mailed' => false];
        }

        $mailed = $this->issueForUser($userId, $clientIp);

        return ['ok' => true, 'already_verified' => false, 'mailed' => $mailed];
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
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

    private function verificationUrl(string $rawToken): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/verify-email/' . $rawToken;
    }
}
