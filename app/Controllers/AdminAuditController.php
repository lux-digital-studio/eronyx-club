<?php

declare(strict_types=1);

namespace App\Controllers;

use RuntimeException;

final class AdminAuditController extends AdminBaseController
{
    public function index(): string
    {
        $result = $this->admin->audit($this->queryFilters());

        return $this->view('admin/audit/index.php', $result + [
            'activeNav' => 'audit',
            'indexUrl' => $this->url('/admin/audit'),
            'pageUrl' => fn (int $page): string => $this->pageUrl('/admin/audit', $result['filters'], $page),
        ]);
    }

    public function show(string $id): ?string
    {
        $auditId = $this->routeId($id);

        if ($auditId === null) {
            return $this->notFound();
        }

        try {
            $entry = $this->admin->auditDetail($auditId);
        } catch (RuntimeException) {
            return $this->notFound();
        }

        return $this->view('admin/audit/show.php', [
            'entry' => $entry,
            'activeNav' => 'audit',
            'indexUrl' => $this->url('/admin/audit'),
        ]);
    }
}
