<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\UserRepository;
use App\Services\EmailVerificationService;
use App\Services\RateLimiter;
use Throwable;

final class EmailVerificationController
{
    private Request $request;
    private Response $response;
    private Session $session;
    private Auth $auth;
    private Csrf $csrf;
    private UserRepository $users;
    private EmailVerificationService $verification;
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
        $this->users = new UserRepository($pdo);
        $this->verification = new EmailVerificationService($pdo, $this->users);
        $this->rateLimiter = new RateLimiter();
        $this->security = require dirname(__DIR__, 2) . '/config/security.php';
    }

    public function status(): string
    {
        $userId = (int) $this->auth->id();
        $verified = $this->verification->isVerified($userId);

        return $this->view('account/verify-email.php', [
            'csrf' => $this->csrf->token(),
            'verified' => $verified,
            'notice' => $this->takeNotice(),
            'error' => '',
            'resendAction' => $this->url('/account/verify-email/resend'),
            'accountUrl' => $this->url('/account'),
        ]);
    }

    public function resend(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $userId = (int) $this->auth->id();
        $limit = $this->rateLimitConfig();
        $userKey = 'email_verification_resend:user:' . $userId;
        $ipKey = 'email_verification_resend:ip:' . $this->request->clientIp();

        if (
            $this->rateLimiter->tooManyAttempts($userKey, $limit['max'])
            || $this->rateLimiter->tooManyAttempts($ipKey, $limit['max'])
        ) {
            return $this->rejectRateLimit(max(
                $this->rateLimiter->retryAfter($userKey),
                $this->rateLimiter->retryAfter($ipKey)
            ));
        }

        if ($this->verification->isVerified($userId)) {
            $this->session->put('verify_notice', 'Tu correo ya está verificado.');
            $this->response->redirect($this->url('/account/verify-email'));

            return null;
        }

        $this->rateLimiter->hit($userKey, $limit['decay']);
        $this->rateLimiter->hit($ipKey, $limit['decay']);

        try {
            $result = $this->verification->resend($userId, $this->request->clientIp());
        } catch (Throwable) {
            return $this->statusWithError('No se pudo enviar el correo. Inténtalo de nuevo.');
        }

        if ($result['already_verified']) {
            $this->session->put('verify_notice', 'Tu correo ya está verificado.');
        } elseif ($result['mailed']) {
            $this->session->put('verify_notice', 'Hemos enviado un nuevo enlace de verificación.');
        } else {
            $this->session->put('verify_notice', 'No se pudo enviar el correo. Puedes reintentar el envío.');
        }

        $this->csrf->regenerate();
        $this->response->redirect($this->url('/account/verify-email'));

        return null;
    }

    /**
     * Public GET verification link. Consume is conditional and idempotent:
     * a reused or invalid token shows the same generic page.
     */
    public function verify(string $token): ?string
    {
        try {
            $result = $this->verification->verifyToken($token);
        } catch (Throwable) {
            return $this->invalid();
        }

        if (!$result['ok']) {
            return $this->invalid();
        }

        if ($this->auth->check()) {
            $this->session->put('verify_notice', 'Tu correo ha sido verificado.');
            $this->response->redirect($this->url('/account/verify-email'));
        } else {
            $this->session->put('auth_notice', 'Correo verificado. Ya puedes iniciar sesión.');
            $this->response->redirect($this->url('/login'));
        }

        return null;
    }

    private function statusWithError(string $error): string
    {
        return $this->view('account/verify-email.php', [
            'csrf' => $this->csrf->token(),
            'verified' => false,
            'notice' => '',
            'error' => $error,
            'resendAction' => $this->url('/account/verify-email/resend'),
            'accountUrl' => $this->url('/account'),
        ]);
    }

    private function invalid(): ?string
    {
        $html = $this->view('auth/verify-email-invalid.php', [
            'loginUrl' => $this->url('/login'),
            'verifyUrl' => $this->url('/account/verify-email'),
        ]);
        $this->response->send($html, 404);

        return null;
    }

    private function takeNotice(): string
    {
        $notice = $this->session->get('verify_notice');
        $this->session->remove('verify_notice');

        return is_string($notice) ? $notice : '';
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
    private function rateLimitConfig(): array
    {
        $limits = $this->security['rate_limits']['email_verification_resend'] ?? [];

        return [
            'max' => max(1, (int) ($limits['max'] ?? 5)),
            'decay' => max(1, (int) ($limits['decay'] ?? 3600)),
        ];
    }

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }
}
