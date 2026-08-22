<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\AuditLogRepository;
use App\Services\AuthService;
use App\Services\MfaService;
use App\Services\RateLimiter;

final class MfaChallengeController
{
    private Request $request;
    private Response $response;
    private Session $session;
    private Csrf $csrf;
    private AuthService $auth;
    private MfaService $mfa;
    private AuditLogRepository $audit;
    private RateLimiter $rateLimiter;
    /** @var array<string, mixed> */
    private array $security;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $this->session = new Session();
        $this->csrf = new Csrf($this->session);
        $pdo = (new Database())->connection();
        $this->auth = new AuthService($this->session, $pdo);
        $this->mfa = new MfaService($this->session, $pdo);
        $this->audit = new AuditLogRepository($pdo);
        $this->rateLimiter = new RateLimiter();
        $this->security = require dirname(__DIR__, 2) . '/config/security.php';
    }

    public function show(): ?string
    {
        if ($this->auth->pendingMfaUserId() === null) {
            $this->response->redirect($this->url('/login'));

            return null;
        }

        return $this->form([]);
    }

    public function verify(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            $this->response->send('Solicitud no válida.', 403);

            return null;
        }

        $userId = $this->auth->pendingMfaUserId();

        if ($userId === null) {
            $this->response->redirect($this->url('/login'));

            return null;
        }

        $limit = $this->rateLimitConfig('mfa_challenge');
        $userKey = 'mfa_challenge:user:' . $userId;
        $ipKey = 'mfa_challenge:ip:' . $this->request->clientIp();

        if (
            $this->rateLimiter->tooManyAttempts($userKey, $limit['max'])
            || $this->rateLimiter->tooManyAttempts($ipKey, $limit['max'])
        ) {
            $this->response->tooManyRequests(max(
                $this->rateLimiter->retryAfter($userKey),
                $this->rateLimiter->retryAfter($ipKey)
            ));

            return null;
        }

        $method = (string) $this->request->input('method', '');
        $code = trim((string) $this->request->input('code', ''));
        $recovery = trim((string) $this->request->input('recovery_code', ''));
        $useRecovery = $method === 'recovery' || ($recovery !== '' && $code === '');
        $accepted = $useRecovery
            ? $this->mfa->useRecoveryCode($userId, $recovery)
            : $this->mfa->verifyLoginCode($userId, $code);

        if (!$accepted) {
            $this->rateLimiter->hit($userKey, $limit['decay']);
            $this->rateLimiter->hit($ipKey, $limit['decay']);
            $this->audit->record($userId, 'mfa_challenge_failed', 'user', $userId);

            return $this->form(['auth' => MfaService::INVALID_CODE]);
        }

        if (!$this->auth->completeMfaLogin($userId)) {
            return $this->form(['auth' => MfaService::INVALID_CODE]);
        }

        $this->rateLimiter->reset($userKey);
        $this->rateLimiter->reset($ipKey);
        $this->csrf->regenerate();
        $this->response->redirect($this->url('/'));

        return null;
    }

    /** @param array<string, string> $errors */
    private function form(array $errors): string
    {
        extract([
            'csrf' => $this->csrf->token(),
            'errors' => $errors,
            'action' => $this->url('/mfa/challenge'),
            'loginUrl' => $this->url('/login'),
        ], EXTR_SKIP);
        ob_start();
        require dirname(__DIR__) . '/Views/auth/mfa-challenge.php';

        return (string) ob_get_clean();
    }

    /** @return array{max: int, decay: int} */
    private function rateLimitConfig(string $name): array
    {
        $limits = $this->security['rate_limits'][$name] ?? [];

        return [
            'max' => max(1, (int) ($limits['max'] ?? 10)),
            'decay' => max(1, (int) ($limits['decay'] ?? 900)),
        ];
    }

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }
}
