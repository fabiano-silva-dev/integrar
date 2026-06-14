<?php

namespace Database\Factories;

use App\Models\EmpresasOperadora;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Empresa>
 */
class EmpresaFactory extends Factory
{
    private static int $cnpjSequence = 1;

    public function definition(): array
    {
        $seq = str_pad((string) self::$cnpjSequence++, 8, '0', STR_PAD_LEFT);

        return [
            'nome' => fake()->company(),
            'cnpj' => sprintf('%02d.%s.%s/0001-%02d', fake()->numberBetween(10, 99), substr($seq, 0, 3), substr($seq, 3, 3), fake()->numberBetween(10, 99)),
            'codigo_sistema' => (string) fake()->numberBetween(1, 999),
            'empresa_operadora_id' => EmpresasOperadora::factory(),
        ];
    }
}
