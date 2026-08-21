<?php

declare(strict_types=1);

namespace App\Controllers;

use RuntimeException;

final class AdminUserController extends AdminBaseController
{
    public function index(): string
    {
        $result = $this->admin->users($this->queryFilters());

        return $this->view('admin/users/index.php', $result + [
            'activeNav' => 'users',
            'indexUrl' => $this->url('/admin/users'),
            'pageUrl' => fn (int $page): string => $this->pageUrl('/admin/users', $result['filters'], $page),
        ]);
    }

    public function show(string $id): ?string
    {
        $userId = $this->routeId($id);

        if ($userId === null) {
            return $this->notFound();
        }

        try {
            $user = $this->admin->userDetail($userId);
        } catch (RuntimeException) {
            return $this->notFound();
        }

        $canManage = $this->canManage($user);

        return $this->view('admin/users/show.php', [
            'user' => $user,
            'canManage' => $canManage,
            'activeNav' => 'users',
            'indexUrl' => $this->url('/admin/users'),
            'suspendUrl' => $this->url('/admin/users/' . $userId . '/suspend'),
            'reactivateUrl' => $this->url('/admin/users/' . $userId . '/reactivate'),
        ]);
    }

    public function suspend(string $id): ?string
    {
        return $this->mutate($id, 'suspend');
    }

    public function reactivate(string $id): ?string
    {
        return $this->mutate($id, 'reactivate');
    }

    private function mutate(string $id, string $action): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $userId = $this->routeId($id);
        $actorId = $this->auth->id();

        if ($userId === null || $actorId === null) {
            return $this->notFound();
        }

        $result = $action === 'suspend'
            ? $this->admin->suspendUser($actorId, $userId)
            : $this->admin->reactivateUser($actorId, $userId);

        if (($result['reason'] ?? '') === 'self' || ($result['reason'] ?? '') === 'privileged') {
            return $this->forbidden();
        }

        if (($result['reason'] ?? '') === 'not_found') {
            return $this->notFound();
        }

        if ($result['ok']) {
            $this->flash($action === 'suspend' ? 'Cuenta suspendida.' : 'Cuenta reactivada.');
            $this->csrf->regenerate();
        } else {
            $this->flash($action === 'suspend' ? 'No se pudo suspender la cuenta.' : 'No se pudo reactivar la cuenta.');
        }

        $this->response->redirect($this->url('/admin/users/' . $userId));

        return null;
    }

    /** @param array<string, mixed> $user */
    private function canManage(array $user): bool
    {
        $roles = $user['roles'] ?? [];
        $actorId = $this->auth->id();

        if ($actorId !== null && $actorId === (int) $user['id']) {
            return false;
        }

        if (in_array('admin', $roles, true) || in_array('moderator', $roles, true)) {
            return false;
        }

        if (($user['deleted_at'] ?? null) !== null) {
            return false;
        }

        return in_array((string) $user['status'], ['active', 'suspended'], true);
    }
}
