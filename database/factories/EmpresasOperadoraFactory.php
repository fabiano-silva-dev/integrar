<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmpresasOperadora>
 */
class EmpresasOperadoraFactory extends Factory
{
    private static int $cnpjSequence = 1;

    public function definition(): array
    {
        $seq = str_pad((string) self::$cnpjSequence++, 8, '0', STR_PAD_LEFT);

        return [
            'razao_social' => fake()->company(),
            'nome_fantasia' => fake()->companySuffix(),
            'cnpj' => sprintf('%02d.%s.%s/0001-%02d', fake()->numberBetween(10, 99), substr($seq, 0, 3), substr($seq, 3, 3), fake()->numberBetween(10, 99)),
            'ativo' => true,
        ];
    }
}
