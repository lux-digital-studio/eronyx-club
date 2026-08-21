<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Authorization;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\UserRepository;
use App\Services\AdminService;

abstract class AdminBaseController
{
    protected Request $request;
    protected Response $response;
    protected Session $session;
    protected Auth $auth;
    protected Authorization $authorization;
    protected Csrf $csrf;
    protected AdminService $admin;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $this->session = new Session();
        $this->auth = new Auth($this->session);
        $this->csrf = new Csrf($this->session);
        $pdo = (new Database())->connection();
        $this->authorization = new Authorization($this->auth, new UserRepository($pdo));
        $this->admin = new AdminService($pdo);
    }

    /** @param array<string, mixed> $data */
    protected function view(string $view, array $data): string
    {
        $data['adminNav'] = $this->navItems();
        $data['showModeratorLinks'] = $this->authorization->hasRole('moderator');
        $data['csrf'] = $this->csrf->token();
        $data['notice'] = $this->takeNotice();
        extract($data, EXTR_SKIP);

        ob_start();
        require dirname(__DIR__) . '/Views/' . $view;

        return (string) ob_get_clean();
    }

    /** @return list<array{href: string, label: string, key: string}> */
    protected function navItems(): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => $this->url('/admin')],
            ['key' => 'users', 'label' => 'Usuarios', 'href' => $this->url('/admin/users')],
            ['key' => 'creators', 'label' => 'Creators', 'href' => $this->url('/admin/creators')],
            ['key' => 'listings', 'label' => 'Listings', 'href' => $this->url('/admin/listings')],
            ['key' => 'orders', 'label' => 'Pedidos', 'href' => $this->url('/admin/orders')],
            ['key' => 'reports', 'label' => 'Reportes', 'href' => $this->url('/admin/reports')],
            ['key' => 'audit', 'label' => 'Auditoría', 'href' => $this->url('/admin/audit')],
        ];
    }

    protected function queryFilters(): array
    {
        return $_GET;
    }

    protected function pageUrl(string $path, array $filters, int $page): string
    {
        $query = $filters;
        $query['page'] = $page;
        $query = array_filter($query, static fn (mixed $value): bool => $value !== '' && $value !== null);

        return $this->url($path) . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    protected function routeId(string $id): ?int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return null;
        }

        return (int) $id;
    }

    protected function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }

    protected function notFound(): ?string
    {
        $this->response->notFound();

        return null;
    }

    protected function forbidden(): ?string
    {
        $this->response->forbidden();

        return null;
    }

    protected function rejectCsrf(): ?string
    {
        $this->response->send('Solicitud no válida.', 403);

        return null;
    }

    protected function flash(string $message): void
    {
        $this->session->put('admin_notice', $message);
    }

    protected function takeNotice(): string
    {
        $notice = $this->session->get('admin_notice');
        $this->session->remove('admin_notice');

        return is_string($notice) ? $notice : '';
    }
}
