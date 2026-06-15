<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Importacao;
use App\Models\Lancamento;
use App\Models\User;
use App\Services\OperadoraContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TenantLancamentoBulkInsertTest extends TestCase
{
    use DatabaseTransactions;

    public function test_insert_many_preenche_empresa_operadora_id_pela_empresa(): void
    {
        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->create(['empresa_operadora_id' => $operadora->id]);
        $empresa = Empresa::factory()->create(['empresa_operadora_id' => $operadora->id]);

        $this->actingAs($user);

        $importacao = Importacao::create([
            'nome_arquivo' => 'teste.ofx',
            'nome' => 'Formato OFX',
            'tipo' => 'avancado',
            'status' => 'processando',
            'usuario' => 'Teste',
            'empresa_id' => $empresa->id,
        ]);

        Lancamento::insertMany([
            [
                'data' => '2025-09-01',
                'historico' => 'TESTE BULK',
                'valor' => 100.00,
                'importacao_id' => $importacao->id,
                'empresa_id' => $empresa->id,
                'processado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $lancamento = Lancamento::where('historico', 'TESTE BULK')->first();

        $this->assertNotNull($lancamento);
        $this->assertEquals($operadora->id, $lancamento->empresa_operadora_id);
    }

    public function test_insert_many_usa_contexto_quando_empresa_id_ausente(): void
    {
        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->create(['empresa_operadora_id' => $operadora->id]);
        $empresa = Empresa::factory()->create(['empresa_operadora_id' => $operadora->id]);

        $this->actingAs($user);

        $importacao = Importacao::create([
            'nome_arquivo' => 'teste2.ofx',
            'nome' => 'Formato OFX',
            'tipo' => 'avancado',
            'status' => 'processando',
            'usuario' => 'Teste',
            'empresa_id' => $empresa->id,
        ]);

        Lancamento::insertMany([
            [
                'data' => '2025-09-02',
                'historico' => 'TESTE BULK IMPORTACAO',
                'valor' => 50.00,
                'importacao_id' => $importacao->id,
                'processado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $lancamento = Lancamento::where('historico', 'TESTE BULK IMPORTACAO')->first();

        $this->assertNotNull($lancamento);
        $this->assertEquals($operadora->id, $lancamento->empresa_operadora_id);
    }

    public function test_insert_many_falha_sem_operadora_resolvivel(): void
    {
        OperadoraContext::disableScope();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('empresa_operadora_id');

            Lancamento::insertMany([
                [
                    'data' => '2025-09-03',
                    'historico' => 'SEM OPERADORA',
                    'valor' => 10.00,
                    'processado' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        } finally {
            OperadoraContext::enableScope();
        }
    }
}
