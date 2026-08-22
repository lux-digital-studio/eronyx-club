<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\SeoService;

final class RobotsController
{
    public function txt(): ?string
    {
        (new Response())->sendRaw((new SeoService())->robotsTxt(), 'text/plain; charset=UTF-8');

        return null;
    }
}
