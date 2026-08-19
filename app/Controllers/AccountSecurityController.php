<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AccountSecurityService;
use App\Services\RateLimiter;
use Throwable;

final class AccountSecurityController
{
    private Request $request;
    private Response $response;
    private Session $session;
    private Auth $auth;
    private Csrf $csrf;
    private AccountSecurityService $securityService;
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
        $this->securityService = new AccountSecurityService($this->session, $pdo);
        $this->rateLimiter = new RateLimiter();
        $this->security = require dirname(__DIR__, 2) . '/config/security.php';
    }

    public function passwordForm(): string
    {
        return $this->renderForm([], $this->takeNotice());
    }

    public function changePassword(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $limit = $this->rateLimitConfig('change_password');
        $key = 'change_password:user:' . $this->auth->id();

        if ($this->rateLimiter->tooManyAttempts($key, $limit['max'])) {
            return $this->rejectRateLimit($this->rateLimiter->retryAfter($key));
        }

        try {
            $result = $this->securityService->changePassword((int) $this->auth->id(), $this->request->all());
        } catch (Throwable) {
            $this->rateLimiter->hit($key, $limit['decay']);

            return $this->renderForm(['auth' => 'No se pudo actualizar la contraseña. Inténtalo de nuevo.']);
        }

        if (!$result['ok']) {
            $this->rateLimiter->hit($key, $limit['decay']);

            return $this->renderForm($result['errors']);
        }

        $this->rateLimiter->reset($key);
        $this->csrf->regenerate();
        $this->session->put('security_notice', 'Contraseña actualizada.');
        $this->response->redirect($this->url('/account/security/password'));

        return null;
    }

    /** @param array<string, string> $errors */
    private function renderForm(array $errors, string $notice = ''): string
    {
        return $this->view('account/security/password.php', [
            'csrf' => $this->csrf->token(),
            'errors' => $errors,
            'notice' => $notice,
            'action' => $this->url('/account/security/password'),
            'accountUrl' => $this->url('/account'),
        ]);
    }

    private function takeNotice(): string
    {
        $notice = $this->session->get('security_notice');
        $this->session->remove('security_notice');

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
    private function rateLimitConfig(string $name): array
    {
        $limits = $this->security['rate_limits'][$name] ?? [];

        return [
            'max' => max(1, (int) ($limits['max'] ?? 10)),
            'decay' => max(1, (int) ($limits['decay'] ?? 3600)),
        ];
    }

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }
}
