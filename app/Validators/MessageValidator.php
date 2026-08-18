<?php

declare(strict_types=1);

namespace App\Validators;

final class MessageValidator
{
    public const MAX_LENGTH = 2000;

    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, errors: array<string, string>, data: array{body: string}}
     */
    public function validate(array $input): array
    {
        $body = trim((string) ($input['body'] ?? ''));
        $errors = [];

        if ($body === '') {
            $errors['body'] = 'El mensaje no puede estar vacío.';
        } elseif ($this->length($body) > self::MAX_LENGTH) {
            $errors['body'] = 'El mensaje no puede superar ' . self::MAX_LENGTH . ' caracteres.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'data' => [
                'body' => $body,
            ],
        ];
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
