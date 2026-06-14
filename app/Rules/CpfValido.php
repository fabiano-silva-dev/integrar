<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CpfValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $digits = self::digits($value);

        if (strlen($digits) !== 11) {
            $fail('CPF deve conter 11 dígitos.');

            return;
        }

        if (!self::isValid($digits)) {
            $fail('CPF inválido.');
        }
    }

    public static function digits(?string $cpf): string
    {
        return preg_replace('/\D/', '', (string) $cpf);
    }

    public static function format(?string $cpf): string
    {
        $digits = self::digits($cpf);

        if (strlen($digits) !== 11) {
            return (string) $cpf;
        }

        return sprintf(
            '%s.%s.%s-%s',
            substr($digits, 0, 3),
            substr($digits, 3, 3),
            substr($digits, 6, 3),
            substr($digits, 9, 2)
        );
    }

    public static function isValid(?string $cpf): bool
    {
        $digits = self::digits($cpf);

        if (strlen($digits) !== 11) {
            return false;
        }

        if (preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $soma = 0;

            for ($j = 0; $j < $t; $j++) {
                $soma += (int) $digits[$j] * (($t + 1) - $j);
            }

            $resto = $soma % 11;
            $digito = $resto < 2 ? 0 : 11 - $resto;

            if ((int) $digits[$t] !== $digito) {
                return false;
            }
        }

        return true;
    }
}
