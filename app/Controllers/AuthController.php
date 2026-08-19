<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\RateLimiter;
use App\Validators\LoginValidator;
use App\Validators\RegisterValidator;
use Throwable;

final class AuthController
{
    private Request $request;
    private Response $response;
    private Session $session;
    private Csrf $csrf;
    private UserRepository $users;
    private AuthService $auth;
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
        $this->users = new UserRepository($pdo);
        $this->auth = new AuthService($this->session, $pdo, $this->users);
        $this->rateLimiter = new RateLimiter();
        $this->security = require dirname(__DIR__, 2) . '/config/security.php';
    }

    public function showRegister(): string
    {
        return $this->view('auth/register.php', [
            'csrf' => $this->csrf->token(),
            'errors' => [],
            'old' => [],
            'action' => $this->url('/register'),
            'loginUrl' => $this->url('/login'),
        ]);
    }

    public function register(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $registerLimit = $this->rateLimitConfig('register');
        $registerKey = $this->registerRateKey();

        if ($this->rateLimiter->tooManyAttempts($registerKey, $registerLimit['max'])) {
            return $this->rejectRateLimit($this->rateLimiter->retryAfter($registerKey));
        }

        $this->rateLimiter->hit($registerKey, $registerLimit['decay']);

        $validation = (new RegisterValidator($this->users))->validate($this->request->all());

        if (!$validation['valid']) {
            return $this->view('auth/register.php', [
                'csrf' => $this->csrf->token(),
                'errors' => $validation['errors'],
                'old' => $this->old(['display_name', 'username', 'email']),
                'action' => $this->url('/register'),
                'loginUrl' => $this->url('/login'),
            ]);
        }

        try {
            $this->auth->register($validation['data']);
            $this->csrf->regenerate();
            $this->response->redirect($this->url('/'));
        } catch (Throwable) {
            return $this->view('auth/register.php', [
                'csrf' => $this->csrf->token(),
                'errors' => ['auth' => 'No se pudo completar el registro. Inténtalo de nuevo.'],
                'old' => $this->old(['display_name', 'username', 'email']),
                'action' => $this->url('/register'),
                'loginUrl' => $this->url('/login'),
            ]);
        }

        return null;
    }

    public function showLogin(): string
    {
        return $this->view('auth/login.php', [
            'csrf' => $this->csrf->token(),
            'errors' => [],
            'old' => [],
            'notice' => $this->takeNotice(),
            'action' => $this->url('/login'),
            'registerUrl' => $this->url('/register'),
            'forgotUrl' => $this->url('/forgot-password'),
        ]);
    }

    public function login(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $loginLimit = $this->rateLimitConfig('login');
        $ipKey = $this->loginIpKey();
        $identityKey = $this->loginIdentityKey((string) $this->request->input('email', ''));

        if (
            $this->rateLimiter->tooManyAttempts($ipKey, $loginLimit['max'])
            || $this->rateLimiter->tooManyAttempts($identityKey, $loginLimit['max'])
        ) {
            return $this->rejectRateLimit(max(
                $this->rateLimiter->retryAfter($ipKey),
                $this->rateLimiter->retryAfter($identityKey)
            ));
        }

        $validation = (new LoginValidator())->validate($this->request->all());

        if (!$validation['valid'] || !$this->auth->attempt($validation['data']['email'], $validation['data']['password'])) {
            $this->rateLimiter->hit($ipKey, $loginLimit['decay']);
            $this->rateLimiter->hit($identityKey, $loginLimit['decay']);

            return $this->view('auth/login.php', [
                'csrf' => $this->csrf->token(),
                'errors' => ['auth' => 'Email o contraseña incorrectos.'],
                'old' => $this->old(['email']),
                'notice' => '',
                'action' => $this->url('/login'),
                'registerUrl' => $this->url('/register'),
                'forgotUrl' => $this->url('/forgot-password'),
            ]);
        }

        $this->rateLimiter->reset($ipKey);
        $this->rateLimiter->reset($identityKey);
        $this->csrf->regenerate();
        $this->response->redirect($this->url('/'));

        return null;
    }

    public function logout(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $this->auth->logout();
        $this->response->redirect($this->url('/login'));

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

    /** @param list<string> $keys @return array<string, string> */
    private function old(array $keys): array
    {
        $old = [];

        foreach ($keys as $key) {
            $old[$key] = (string) $this->request->input($key, '');
        }

        return $old;
    }

    private function takeNotice(): string
    {
        $notice = $this->session->get('auth_notice');
        $this->session->remove('auth_notice');

        return is_string($notice) ? $notice : '';
    }

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
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
            'max' => max(1, (int) ($limits['max'] ?? 5)),
            'decay' => max(1, (int) ($limits['decay'] ?? 900)),
        ];
    }

    private function loginIpKey(): string
    {
        return 'login:ip:' . $this->request->clientIp();
    }

    private function loginIdentityKey(string $email): string
    {
        return 'login:id:' . hash('sha256', strtolower(trim($email)));
    }

    private function registerRateKey(): string
    {
        return 'register:ip:' . $this->request->clientIp();
    }
}
