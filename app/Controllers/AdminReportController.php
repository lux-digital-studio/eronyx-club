<?php

declare(strict_types=1);

namespace App\Controllers;

use RuntimeException;

final class AdminReportController extends AdminBaseController
{
    public function index(): string
    {
        $result = $this->admin->reports($this->queryFilters());

        return $this->view('admin/reports/index.php', $result + [
            'activeNav' => 'reports',
            'indexUrl' => $this->url('/admin/reports'),
            'pageUrl' => fn (int $page): string => $this->pageUrl('/admin/reports', $result['filters'], $page),
        ]);
    }

    public function show(string $id): ?string
    {
        $reportId = $this->routeId($id);

        if ($reportId === null) {
            return $this->notFound();
        }

        try {
            $report = $this->admin->reportDetail($reportId);
        } catch (RuntimeException) {
            return $this->notFound();
        }

        return $this->view('admin/reports/show.php', [
            'report' => $report,
            'activeNav' => 'reports',
            'indexUrl' => $this->url('/admin/reports'),
            'moderatorUrl' => $this->url('/moderator/reports/' . $reportId),
        ]);
    }
}
