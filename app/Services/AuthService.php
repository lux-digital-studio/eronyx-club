<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;
use App\Repositories\MfaRepository;
use App\Repositories\UserRepository;
use RuntimeException;
use Throwable;

final class AuthService
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$EBVKylk7vGJdYIgg29F4SOO4OfEyR6RJkWv12k3Nm81l8h4yjPotS';

    private \PDO $pdo;
    private UserRepository $users;
    private EmailVerificationService $verification;
    private MfaRepository $mfa;

    public function __construct(
        private readonly Session $session,
        ?\PDO $pdo = null,
        ?UserRepository $users = null,
        ?EmailVerificationService $verification = null,
        ?MfaRepository $mfa = null
    ) {
        $this->pdo = $pdo ?? (new Database())->connection();
        $this->users = $users ?? new UserRepository($this->pdo);
        $this->verification = $verification ?? new EmailVerificationService($this->pdo, $this->users);
        $this->mfa = $mfa ?? new MfaRepository($this->pdo);
    }

    /** @param array{email: string, username: string, display_name: string, password: string} $data */
    public function register(array $data, string $clientIp = '0.0.0.0'): int
    {
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            throw new RuntimeException('Unable to securely hash password.');
        }

        try {
            $this->pdo->beginTransaction();

            $userId = $this->users->createUser($data['email'], $passwordHash, 'active');
            $this->users->createProfile($userId, $data['display_name'], $data['username']);

            $buyerRoleId = $this->users->findRoleIdByName('buyer');

            if ($buyerRoleId === null) {
                throw new RuntimeException('Required buyer role is missing.');
            }

            $this->users->assignRole($userId, $buyerRoleId);
            (new UserConsentService($this->pdo))->recordRegisterConsents($userId);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $this->loginUserId($userId, 1);

        try {
            $this->verification->issueForUser($userId, $clientIp);
        } catch (Throwable) {
        }

        return $userId;
    }

    public function attempt(string $email, string $password): bool
    {
        $result = $this->authenticate($email, $password);

        return $result['ok'] && !$result['mfa_required'];
    }

    /** @return array{ok: bool, mfa_required: bool} */
    public function authenticate(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);
        $hash = is_array($user) && is_string($user['password_hash'] ?? null)
            ? $user['password_hash']
            : self::DUMMY_PASSWORD_HASH;
        $verified = password_verify($password, $hash);

        if (!is_array($user) || $user['status'] !== 'active' || !$verified) {
            return ['ok' => false, 'mfa_required' => false];
        }

        $userId = (int) $user['id'];

        try {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $rehashed = password_hash($password, PASSWORD_DEFAULT);

                if (is_string($rehashed)) {
                    $this->users->updatePasswordHash($userId, $rehashed);
                }
            }

            if ($this->mfa->isEnabled($userId)) {
                $this->beginMfaChallenge($userId);

                return ['ok' => true, 'mfa_required' => true];
            }

            $this->loginUserId($userId, max(1, (int) ($user['session_version'] ?? 1)));
            $this->users->updateLastLoginAt($userId);
        } catch (Throwable) {
            $this->logout();

            return ['ok' => false, 'mfa_required' => false];
        }

        return ['ok' => true, 'mfa_required' => false];
    }

    public function beginMfaChallenge(int $userId): void
    {
        $this->session->regenerate();
        $this->session->remove('auth_user_id');
        $this->session->remove('auth_session_version');
        $this->session->put('mfa_pending_user_id', $userId);
    }

    public function pendingMfaUserId(): ?int
    {
        $id = $this->session->get('mfa_pending_user_id');

        if (is_int($id) && $id > 0) {
            return $id;
        }

        if (is_string($id) && ctype_digit($id) && (int) $id > 0) {
            return (int) $id;
        }

        return null;
    }

    public function completeMfaLogin(int $userId): bool
    {
        $user = $this->users->findAuthById($userId);

        if ($user === null || $user['status'] !== 'active' || $user['deleted_at'] !== null) {
            $this->clearMfaPending();

            return false;
        }

        $this->session->remove('mfa_pending_user_id');
        $this->loginUserId($userId, $user['session_version']);
        $this->users->updateLastLoginAt($userId);

        return true;
    }

    public function clearMfaPending(): void
    {
        $this->session->remove('mfa_pending_user_id');
    }

    public function logout(): void
    {
        $this->session->remove('auth_user_id');
        $this->session->remove('mfa_pending_user_id');
        $this->session->invalidate();
    }

    private function loginUserId(int $userId, ?int $sessionVersion = null): void
    {
        $version = $sessionVersion ?? $this->users->sessionVersion($userId);
        $this->session->regenerate();
        $this->session->remove('mfa_pending_user_id');
        $this->session->put('auth_user_id', $userId);
        $this->session->put('auth_session_version', $version);
    }
}
