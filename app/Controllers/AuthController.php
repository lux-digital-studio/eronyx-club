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

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $this->session = new Session();
        $this->csrf = new Csrf($this->session);

        $pdo = (new Database())->connection();
        $this->users = new UserRepository($pdo);
        $this->auth = new AuthService($this->session, $pdo, $this->users);
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
            'action' => $this->url('/login'),
            'registerUrl' => $this->url('/register'),
        ]);
    }

    public function login(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $validation = (new LoginValidator())->validate($this->request->all());

        if (!$validation['valid'] || !$this->auth->attempt($validation['data']['email'], $validation['data']['password'])) {
            return $this->view('auth/login.php', [
                'csrf' => $this->csrf->token(),
                'errors' => ['auth' => 'Email o contraseña incorrectos.'],
                'old' => $this->old(['email']),
                'action' => $this->url('/login'),
                'registerUrl' => $this->url('/register'),
            ]);
        }

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
}
