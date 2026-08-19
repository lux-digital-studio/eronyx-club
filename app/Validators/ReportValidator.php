<?php

declare(strict_types=1);

namespace App\Validators;

final class ReportValidator
{
    public const MAX_DETAILS = 2000;

    public const REASON_CODES = [
        'spam',
        'scam',
        'harassment',
        'illegal_content',
        'underage_concern',
        'non_consensual_content',
        'misleading',
        'prohibited_item',
        'other',
    ];

    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, errors: array<string, string>, data: array{reason_code: string, details: string|null}}
     */
    public function validate(array $input): array
    {
        $reasonCode = trim((string) ($input['reason_code'] ?? ''));
        $details = trim((string) ($input['details'] ?? ''));
        $errors = [];

        if (!in_array($reasonCode, self::REASON_CODES, true)) {
            $errors['reason_code'] = 'Selecciona un motivo válido.';
        }

        if ($this->length($details) > self::MAX_DETAILS) {
            $errors['details'] = 'Los detalles no pueden superar ' . self::MAX_DETAILS . ' caracteres.';
        }

        if ($reasonCode === 'other' && $details === '' && !isset($errors['details'])) {
            $errors['details'] = 'Describe el motivo si seleccionas Otro.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'data' => [
                'reason_code' => $reasonCode,
                'details' => $details === '' ? null : $details,
            ],
        ];
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
