<?php

declare(strict_types=1);

namespace App\Controllers;

use RuntimeException;

final class AdminCreatorController extends AdminBaseController
{
    public function index(): string
    {
        $result = $this->admin->creators($this->queryFilters());

        return $this->view('admin/creators/index.php', $result + [
            'activeNav' => 'creators',
            'indexUrl' => $this->url('/admin/creators'),
            'pageUrl' => fn (int $page): string => $this->pageUrl('/admin/creators', $result['filters'], $page),
        ]);
    }

    public function show(string $id): ?string
    {
        $userId = $this->routeId($id);

        if ($userId === null) {
            return $this->notFound();
        }

        try {
            $creator = $this->admin->creatorDetail($userId);
        } catch (RuntimeException) {
            return $this->notFound();
        }

        return $this->view('admin/creators/show.php', [
            'creator' => $creator,
            'activeNav' => 'creators',
            'indexUrl' => $this->url('/admin/creators'),
            'userUrl' => $this->url('/admin/users/' . $userId),
            'moderatorUrl' => $this->url('/moderator/creator-applications'),
        ]);
    }
}
