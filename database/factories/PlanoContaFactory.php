<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PlanoConta>
 */
class PlanoContaFactory extends Factory
{
    public function definition(): array
    {
        $codigo = '1.' . fake()->unique()->numerify('##');

        return [
            'empresa_operadora_id' => EmpresasOperadora::factory(),
            'empresa_id' => Empresa::factory(),
            'codigo' => $codigo,
            'codigo_reduzido' => fake()->numerify('###'),
            'descricao' => fake()->words(3, true),
            'tipo' => 'analitica',
            'natureza' => 'devedora',
            'nivel' => 2,
            'codigo_pai' => '1',
            'aceita_lancamento' => true,
            'ativo' => true,
        ];
    }
}
