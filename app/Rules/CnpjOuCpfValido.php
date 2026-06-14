<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CnpjOuCpfValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || trim((string) $value) === '') {
            return;
        }

        $digits = preg_replace('/\D/', '', (string) $value);
        $tamanho = strlen($digits);

        if ($tamanho === 11) {
            if (!CpfValido::isValid($digits)) {
                $fail('CPF inválido.');
            }

            return;
        }

        if ($tamanho === 14) {
            if (!CnpjValido::isValid($digits)) {
                $fail('CNPJ inválido.');
            }

            return;
        }

        $fail('Informe um CPF (11 dígitos) ou CNPJ (14 dígitos), com ou sem máscara.');
    }

    public static function format(?string $documento): ?string
    {
        if ($documento === null || trim($documento) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $documento);

        return match (strlen($digits)) {
            11 => CpfValido::format($digits),
            14 => CnpjValido::format($digits),
            default => $documento,
        };
    }
}
