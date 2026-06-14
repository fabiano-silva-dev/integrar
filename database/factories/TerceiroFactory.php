<?php

namespace Database\Factories;

use App\Models\EmpresasOperadora;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Terceiro>
 */
class TerceiroFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nome' => fake()->company(),
            'cnpj_cpf' => fake()->numerify('##.###.###/####-##'),
            'tipo' => 'fornecedor',
            'ativo' => true,
            'empresa_operadora_id' => EmpresasOperadora::factory(),
        ];
    }
}
