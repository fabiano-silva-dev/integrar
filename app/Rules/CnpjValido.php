<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CnpjValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $digits = self::digits($value);

        if (strlen($digits) !== 14) {
            $fail('CNPJ deve conter 14 dígitos.');

            return;
        }

        if (!self::isValid($digits)) {
            $fail('CNPJ inválido.');
        }
    }

    public static function digits(?string $cnpj): string
    {
        return preg_replace('/\D/', '', (string) $cnpj);
    }

    public static function format(?string $cnpj): string
    {
        $digits = self::digits($cnpj);

        if (strlen($digits) !== 14) {
            return (string) $cnpj;
        }

        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($digits, 0, 2),
            substr($digits, 2, 3),
            substr($digits, 5, 3),
            substr($digits, 8, 4),
            substr($digits, 12, 2)
        );
    }

    public static function isValid(?string $cnpj): bool
    {
        $digits = self::digits($cnpj);

        if (strlen($digits) !== 14) {
            return false;
        }

        if (preg_match('/^(\d)\1{13}$/', $digits)) {
            return false;
        }

        $pesosPrimeiro = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $pesosSegundo = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        foreach ([$pesosPrimeiro, $pesosSegundo] as $pesos) {
            $soma = 0;

            foreach ($pesos as $indice => $peso) {
                $soma += (int) $digits[$indice] * $peso;
            }

            $resto = $soma % 11;
            $digito = $resto < 2 ? 0 : 11 - $resto;
            $posicao = count($pesos);

            if ((int) $digits[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }
}
