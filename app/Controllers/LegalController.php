<?php

declare(strict_types=1);

namespace App\Controllers;

final class LegalController
{
    public function index(): string
    {
        return $this->page('index.php', 'Información legal - ERONYX');
    }

    public function terms(): string
    {
        return $this->page('terms.php', 'Términos de uso - ERONYX');
    }

    public function privacy(): string
    {
        return $this->page('privacy.php', 'Privacidad - ERONYX');
    }

    public function cookies(): string
    {
        return $this->page('cookies.php', 'Cookies - ERONYX');
    }

    public function contentPolicy(): string
    {
        return $this->page('content-policy.php', 'Política de contenido - ERONYX');
    }

    public function creatorRules(): string
    {
        return $this->page('creator-rules.php', 'Reglas para creators - ERONYX');
    }

    public function agePolicy(): string
    {
        return $this->page('age-policy.php', 'Mayoría de edad - ERONYX');
    }

    public function reportingPolicy(): string
    {
        return $this->page('reporting-policy.php', 'Reportes y retirada - ERONYX');
    }

    private function page(string $view, string $title): string
    {
        $legal = require dirname(__DIR__, 2) . '/config/legal.php';

        ob_start();
        require dirname(__DIR__) . '/Views/legal/' . $view;

        return (string) ob_get_clean();
    }
}
