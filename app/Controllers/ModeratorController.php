<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Repositories\ReportRepository;

final class ModeratorController
{
    public function index(): string
    {
        $openCount = (new ReportRepository((new Database())->connection()))->countOpenReports();

        ob_start();
        $openReportCount = $openCount;
        require dirname(__DIR__) . '/Views/moderator/index.php';

        return (string) ob_get_clean();
    }
}
