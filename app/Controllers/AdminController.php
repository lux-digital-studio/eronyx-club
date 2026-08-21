<?php

declare(strict_types=1);

namespace App\Controllers;

final class AdminController extends AdminBaseController
{
    public function index(): string
    {
        $dashboard = $this->admin->dashboard();

        return $this->view('admin/index.php', [
            'counts' => $dashboard['counts'],
            'recentAudit' => $dashboard['recentAudit'],
            'activeNav' => 'dashboard',
        ]);
    }
}
