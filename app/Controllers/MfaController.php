<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\MfaService;
use App\Services\RateLimiter;
use Throwable;

final class MfaController
{
    private Request $request;
    private Response $response;
    private Session $session;
    private Auth $auth;
    private Csrf $csrf;
    private MfaService $mfa;
    private RateLimiter $rateLimiter;
    /** @var array<string, mixed> */
    private array $security;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $this->session = new Session();
        $this->auth = new Auth($this->session);
        $this->csrf = new Csrf($this->session);
        $pdo = (new Database())->connection();
        $this->mfa = new MfaService($this->session, $pdo);
        $this->rateLimiter = new RateLimiter();
        $this->security = require dirname(__DIR__, 2) . '/config/security.php';
    }

    public function show(): string
    {
        $userId = (int) $this->auth->id();
        $status = $this->mfa->status($userId);

        return $this->view('account/security/mfa/index.php', [
            'csrf' => $this->csrf->token(),
            'status' => $status,
            'errors' => [],
            'notice' => $this->takeNotice(),
            'setupAction' => $this->url('/account/security/mfa/setup'),
            'disableAction' => $this->url('/account/security/mfa/disable'),
            'regenerateAction' => $this->url('/account/security/mfa/recovery/regenerate'),
            'passwordUrl' => $this->url('/account/security/password'),
            'accountUrl' => $this->url('/account'),
        ]);
    }

    public function setup(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $this->ignoreSpoofedUserId();
        $result = $this->mfa->beginSetup((int) $this->auth->id());

        if (!$result['ok']) {
            if (($result['error'] ?? '') === 'unverified') {
                $this->response->redirect($this->url('/account/verify-email'));

                return null;
            }

            $this->session->put('security_notice', 'No se pudo iniciar la configuración MFA.');
            $this->response->redirect($this->url('/account/security/mfa'));

            return null;
        }

        $this->csrf->regenerate();

        return $this->view('account/security/mfa/setup.php', [
            'csrf' => $this->csrf->token(),
            'secret' => (string) ($result['secret'] ?? ''),
            'otpauthUri' => (string) ($result['otpauth_uri'] ?? ''),
            'qrDataUri' => (string) ($result['qr_data_uri'] ?? ''),
            'errors' => [],
            'confirmAction' => $this->url('/account/security/mfa/confirm'),
            'cancelUrl' => $this->url('/account/security/mfa'),
        ]);
    }

    public function confirm(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $this->ignoreSpoofedUserId();
        $limit = $this->rateLimitConfig('mfa_setup_confirm');
        $key = 'mfa_setup_confirm:user:' . $this->auth->id();

        if ($this->rateLimiter->tooManyAttempts($key, $limit['max'])) {
            return $this->rejectRateLimit($this->rateLimiter->retryAfter($key));
        }

        try {
            $result = $this->mfa->confirmSetup((int) $this->auth->id(), (string) $this->request->input('code', ''));
        } catch (Throwable) {
            $this->rateLimiter->hit($key, $limit['decay']);

            return $this->setupRetry([MfaService::INVALID_CODE]);
        }

        if (!$result['ok']) {
            $this->rateLimiter->hit($key, $limit['decay']);

            return $this->setupRetry([$result['error'] ?? MfaService::INVALID_CODE]);
        }

        $this->rateLimiter->reset($key);
        $this->csrf->regenerate();
        $this->mfa->flashRecoveryCodes($result['codes'] ?? []);
        $this->response->redirect($this->url('/account/security/mfa/recovery'));

        return null;
    }

    public function recovery(): string
    {
        $codes = $this->mfa->takeRecoveryCodes();

        return $this->view('account/security/mfa/recovery.php', [
            'codes' => $codes,
            'shownOnce' => $codes !== [],
            'mfaUrl' => $this->url('/account/security/mfa'),
            'accountUrl' => $this->url('/account'),
        ]);
    }

    public function regenerate(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $this->ignoreSpoofedUserId();
        $limit = $this->rateLimitConfig('mfa_recovery_regenerate');
        $key = 'mfa_recovery_regenerate:user:' . $this->auth->id();

        if ($this->rateLimiter->tooManyAttempts($key, $limit['max'])) {
            return $this->rejectRateLimit($this->rateLimiter->retryAfter($key));
        }

        $result = $this->mfa->regenerateRecoveryCodes(
            (int) $this->auth->id(),
            (string) $this->request->input('current_password', ''),
            (string) $this->request->input('mfa_code', '')
        );

        if (!$result['ok']) {
            $this->rateLimiter->hit($key, $limit['decay']);
            $this->session->put('security_notice', $result['error'] === 'not_enabled' ? 'MFA no está activado.' : MfaService::INVALID_CODE);
            $this->response->redirect($this->url('/account/security/mfa'));

            return null;
        }

        $this->rateLimiter->reset($key);
        $this->csrf->regenerate();
        $this->mfa->flashRecoveryCodes($result['codes'] ?? []);
        $this->response->redirect($this->url('/account/security/mfa/recovery'));

        return null;
    }

    public function disable(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $this->ignoreSpoofedUserId();
        $limit = $this->rateLimitConfig('mfa_disable');
        $key = 'mfa_disable:user:' . $this->auth->id();

        if ($this->rateLimiter->tooManyAttempts($key, $limit['max'])) {
            return $this->rejectRateLimit($this->rateLimiter->retryAfter($key));
        }

        $result = $this->mfa->disable(
            (int) $this->auth->id(),
            (string) $this->request->input('current_password', ''),
            (string) $this->request->input('mfa_code', '')
        );

        if (!$result['ok']) {
            $this->rateLimiter->hit($key, $limit['decay']);
            $this->session->put('security_notice', $result['error'] === 'not_enabled' ? 'MFA no está activado.' : MfaService::INVALID_CODE);
            $this->response->redirect($this->url('/account/security/mfa'));

            return null;
        }

        $this->rateLimiter->reset($key);
        $this->csrf->regenerate();
        $this->session->put('security_notice', 'MFA desactivado.');
        $this->response->redirect($this->url('/account/security/mfa'));

        return null;
    }

    /** @param array<string, mixed> $data */
    private function view(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__) . '/Views/' . $view;

        return (string) ob_get_clean();
    }

    /** @param list<string> $errors */
    private function setupRetry(array $errors): string
    {
        return $this->view('account/security/mfa/setup.php', [
            'csrf' => $this->csrf->token(),
            'secret' => '',
            'otpauthUri' => '',
            'qrDataUri' => '',
            'errors' => $errors,
            'confirmAction' => $this->url('/account/security/mfa/confirm'),
            'cancelUrl' => $this->url('/account/security/mfa'),
        ]);
    }

    private function takeNotice(): string
    {
        $notice = $this->session->get('security_notice');
        $this->session->remove('security_notice');

        return is_string($notice) ? $notice : '';
    }

    private function ignoreSpoofedUserId(): void
    {
        // user_id from POST is ignored; actor always comes from Auth.
    }

    private function rejectCsrf(): ?string
    {
        $this->response->send('Solicitud no válida.', 403);

        return null;
    }

    private function rejectRateLimit(int $retryAfter): ?string
    {
        $this->response->tooManyRequests($retryAfter);

        return null;
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
