<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public function __construct(
        private readonly Session $session
    ) {
    }

    public function check(): bool
    {
        return $this->id() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function id(): ?int
    {
        $id = $this->session->get('auth_user_id');

        if (is_int($id) && $id > 0) {
            return $id;
        }

        if (is_string($id) && ctype_digit($id) && (int) $id > 0) {
            return (int) $id;
        }

        return null;
    }

    public function sessionVersion(): int
    {
        $value = $this->session->get('auth_session_version');

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return 1;
    }
}
