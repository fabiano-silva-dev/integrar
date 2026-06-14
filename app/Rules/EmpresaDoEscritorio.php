<?php

namespace App\Rules;

use App\Models\Empresa;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class EmpresaDoEscritorio implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!Empresa::find($value)) {
            $fail('A empresa selecionada não pertence ao seu escritório.');
        }
    }
}
