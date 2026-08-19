<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AccountSecurityService;
use App\Services\RateLimiter;
use Throwable;

final class PasswordResetController
{
    private Request $request;
    private Response $response;
    private Session $session;
    private Csrf $csrf;
    private AccountSecurityService $securityService;
    private RateLimiter $rateLimiter;
    /** @var array<string, mixed> */
    private array $app;
    /** @var array<string, mixed> */
    private array $security;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $this->session = new Session();
        $this->csrf = new Csrf($this->session);
        $pdo = (new Database())->connection();
        $this->securityService = new AccountSecurityService($this->session, $pdo);
        $this->rateLimiter = new RateLimiter();
        $this->app = require dirname(__DIR__, 2) . '/config/app.php';
        $this->security = require dirname(__DIR__, 2) . '/config/security.php';
    }

    public function forgotForm(): string
    {
        return $this->forgotView();
    }

    public function requestReset(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $limit = $this->rateLimitConfig('forgot_password', 5, 3600);
        $email = strtolower(trim((string) $this->request->input('email', '')));
        $ipKey = 'forgot_password:ip:' . $this->request->clientIp();
        $identityKey = 'forgot_password:id:' . hash('sha256', $email);

        if (
            $this->rateLimiter->tooManyAttempts($ipKey, $limit['max'])
            || $this->rateLimiter->tooManyAttempts($identityKey, $limit['max'])
        ) {
            return $this->rejectRateLimit(max(
                $this->rateLimiter->retryAfter($ipKey),
                $this->rateLimiter->retryAfter($identityKey)
            ));
        }

        $this->rateLimiter->hit($ipKey, $limit['decay']);
        $this->rateLimiter->hit($identityKey, $limit['decay']);

        $resetUrl = null;
        $message = AccountSecurityService::GENERIC_FORGOT_MESSAGE;

        if ($email !== '' && strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            try {
                $result = $this->securityService->requestPasswordReset(
                    $email,
                    $this->request->clientIp(),
                    $this->canExposeResetUrl()
                );
                $message = $result['message'];
                $resetUrl = $result['reset_url'];
            } catch (Throwable) {
                $message = AccountSecurityService::GENERIC_FORGOT_MESSAGE;
            }
        }

        $this->csrf->regenerate();

        return $this->forgotView($message, $resetUrl, $this->oldEmail());
    }

    public function resetForm(string $token): ?string
    {
        if (!$this->securityService->tokenIsValid($token)) {
            return $this->invalidToken();
        }

        return $this->resetView($token);
    }

    public function resetPassword(string $token): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $limit = $this->rateLimitConfig('reset_password', 10, 1800);
        $tokenKey = 'reset_password:token:' . hash('sha256', $token);
        $ipKey = 'reset_password:ip:' . $this->request->clientIp();

        if (
            $this->rateLimiter->tooManyAttempts($tokenKey, $limit['max'])
            || $this->rateLimiter->tooManyAttempts($ipKey, $limit['max'])
        ) {
            return $this->rejectRateLimit(max(
                $this->rateLimiter->retryAfter($tokenKey),
                $this->rateLimiter->retryAfter($ipKey)
            ));
        }

        try {
            $result = $this->securityService->resetPassword($token, $this->request->all());
        } catch (Throwable) {
            $this->rateLimiter->hit($tokenKey, $limit['decay']);
            $this->rateLimiter->hit($ipKey, $limit['decay']);

            return $this->resetView($token, ['auth' => 'No se pudo actualizar la contraseña. Inténtalo de nuevo.']);
        }

        if (($result['errors']['token'] ?? null) === 'invalid') {
            $this->rateLimiter->hit($tokenKey, $limit['decay']);
            $this->rateLimiter->hit($ipKey, $limit['decay']);

            return $this->invalidToken();
        }

        if (!$result['ok']) {
            $this->rateLimiter->hit($tokenKey, $limit['decay']);
            $this->rateLimiter->hit($ipKey, $limit['decay']);

            return $this->resetView($token, $result['errors']);
        }

        $this->rateLimiter->reset($tokenKey);
        $this->rateLimiter->reset($ipKey);
        $this->session->put('auth_notice', 'Contraseña actualizada. Ya puedes iniciar sesión.');
        $this->csrf->regenerate();
        $this->response->redirect($this->url('/login'));

        return null;
    }

    private function forgotView(string $message = '', ?string $resetUrl = null, string $oldEmail = ''): string
    {
        return $this->view('auth/forgot-password.php', [
            'csrf' => $this->csrf->token(),
            'message' => $message,
            'resetUrl' => $resetUrl,
            'oldEmail' => $oldEmail,
            'action' => $this->url('/forgot-password'),
            'loginUrl' => $this->url('/login'),
        ]);
    }

    /** @param array<string, string> $errors */
    private function resetView(string $token, array $errors = []): string
    {
        return $this->view('auth/reset-password.php', [
            'csrf' => $this->csrf->token(),
            'errors' => $errors,
            'action' => $this->url('/reset-password/' . $token),
            'loginUrl' => $this->url('/login'),
        ]);
    }

    private function invalidToken(): ?string
    {
        $html = $this->view('auth/reset-invalid.php', [
            'loginUrl' => $this->url('/login'),
            'forgotUrl' => $this->url('/forgot-password'),
        ]);
        $this->response->send($html, 404);

        return null;
    }

    private function canExposeResetUrl(): bool
    {
        return in_array((string) ($this->app['env'] ?? 'local'), ['local', 'test'], true);
    }

    private function oldEmail(): string
    {
        $email = (string) $this->request->input('email', '');

        return strlen($email) > 255 ? '' : $email;
    }

    /** @param array<string, mixed> $data */
    private function view(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require dirname(__DIR__) . '/Views/' . $view;

        return (string) ob_get_clean();
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
    private function rateLimitConfig(string $name, int $defaultMax, int $defaultDecay): array
    {
        $limits = $this->security['rate_limits'][$name] ?? [];

        return [
            'max' => max(1, (int) ($limits['max'] ?? $defaultMax)),
            'decay' => max(1, (int) ($limits['decay'] ?? $defaultDecay)),
        ];
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->app['url'], '/') . '/' . ltrim($path, '/');
    }
}
