<?php

declare(strict_types=1);

namespace App\Validators;

final class CreatorApplicationValidator
{
    /** @param array<string, mixed> $input @return array{valid: bool, errors: array<string, string>} */
    public function validate(array $input): array
    {
        $errors = [];

        if (!$this->accepted($input['adult_confirmation'] ?? null)) {
            $errors['adult_confirmation'] = 'Debes declarar que eres mayor de 18 años.';
        }

        if (!$this->accepted($input['terms_confirmation'] ?? null)) {
            $errors['terms_confirmation'] = 'Debes aceptar las condiciones para solicitar acceso creator.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    private function accepted(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'on', 'true'], true);
    }
}
