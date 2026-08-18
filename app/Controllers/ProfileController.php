<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\MediaRepository;
use App\Repositories\ProfileRepository;
use App\Services\MediaStorageService;
use App\Services\ProfileService;
use App\Validators\ProfileValidator;
use RuntimeException;
use Throwable;

final class ProfileController
{
    private Request $request;
    private Response $response;
    private Csrf $csrf;
    private ProfileService $service;
    private ProfileValidator $validator;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $session = new Session();
        $this->csrf = new Csrf($session);
        $pdo = (new Database())->connection();
        $this->service = new ProfileService(
            new Auth($session),
            $pdo,
            new ProfileRepository($pdo),
            new MediaRepository($pdo),
            new MediaStorageService()
        );
        $this->validator = new ProfileValidator();
    }

    public function edit(): string
    {
        $profile = $this->service->getOwnProfile();

        return $this->form([], $profile);
    }

    public function update(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $validation = $this->validator->validate($this->request->all());

        if (!$validation['valid']) {
            return $this->form($validation['errors'], $this->old());
        }

        try {
            $this->service->updateOwnProfile($validation['data']);
            $this->csrf->regenerate();
            $this->response->redirect($this->url('/account/profile'));
        } catch (RuntimeException $exception) {
            return $this->form(['username' => $exception->getMessage()], $this->old());
        }

        return null;
    }

    public function uploadAvatar(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        try {
            $this->service->uploadAvatar($this->request->file('avatar'));
            $this->csrf->regenerate();
            $this->response->redirect($this->url('/account/profile'));
        } catch (Throwable $exception) {
            return $this->form(['avatar' => $exception->getMessage()], $this->service->getOwnProfile());
        }

        return null;
    }

    public function deleteAvatar(): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $this->service->removeAvatar();
        $this->csrf->regenerate();
        $this->response->redirect($this->url('/account/profile'));

        return null;
    }

    /** @param array<string, string> $errors @param array<string, mixed> $profile */
    private function form(array $errors, array $profile): string
    {
        return $this->view('account/profile/edit.php', [
            'csrf' => $this->csrf->token(),
            'errors' => $errors,
            'profile' => $profile,
            'action' => $this->url('/account/profile'),
            'avatarAction' => $this->url('/account/profile/avatar'),
            'deleteAvatarAction' => $this->url('/account/profile/avatar/delete'),
            'avatarUrl' => isset($profile['avatar_media_id']) && $profile['avatar_media_id'] !== null
                ? $this->url('/media/' . $profile['avatar_media_id'])
                : null,
            'accountUrl' => $this->url('/account'),
        ]);
    }

    /** @return array<string, mixed> */
    private function old(): array
    {
        return [
            'display_name' => (string) $this->request->input('display_name', ''),
            'username' => (string) $this->request->input('username', ''),
            'bio' => (string) $this->request->input('bio', ''),
            'avatar_media_id' => $this->service->getOwnProfile()['avatar_media_id'] ?? null,
        ];
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

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }
}
