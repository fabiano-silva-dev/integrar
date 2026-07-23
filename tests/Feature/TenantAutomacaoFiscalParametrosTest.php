<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\EmpresaIntegracao;
use App\Models\EmpresaIntegracaoRecurso;
use App\Models\EmpresasOperadora;
use App\Models\PortalIntegracao;
use App\Models\PortalRecurso;
use App\Models\User;
use App\Services\AutomacaoFiscal\AutomacaoExecucaoService;
use Database\Seeders\PortaisIntegracaoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TenantAutomacaoFiscalParametrosTest extends TestCase
{
    use DatabaseTransactions;

    public function test_salva_parametros_e_enfileira_com_eles(): void
    {
        config(['automacao_fiscal.fake_mode' => true]);
        Queue::fake();

        $this->seed(PortaisIntegracaoSeeder::class);

        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'role' => 'admin',
        ]);
        $empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'inscricao_estadual' => '1234567890',
            'cnpj' => '12345678000199',
        ]);

        $portal = PortalIntegracao::query()->where('codigo', 'ecac_rs')->firstOrFail();
        $recurso = PortalRecurso::query()
            ->where('portal_integracao_id', $portal->id)
            ->where('codigo', 'nfe_emitidas')
            ->firstOrFail();

        $integracao = EmpresaIntegracao::create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'portal_integracao_id' => $portal->id,
            'ativo' => true,
            'modo_autenticacao' => 'certificado_a1',
            'status_configuracao' => 'configurado',
        ]);

        $this->actingAs($user);

        $service = app(AutomacaoExecucaoService::class);
        $vinculo = $service->garantirVinculo($integracao, $recurso);

        $params = [
            'ie' => '9876543210',
            'cnpj' => '12345678000199',
            'modelo' => 'nfe',
            'periodo_inicial' => '2026-07-01',
            'periodo_final' => '2026-07-20',
            'operacao' => 'saida-consulente',
            'situacao_normal' => true,
            'situacao_cancelada' => false,
            'totalizado_por_mes' => false,
            'excluir_venda_fora_estabelecimento' => false,
        ];

        $service->salvarParametrosRecurso($vinculo, $params);
        $vinculo->refresh();

        $this->assertEquals('9876543210', $vinculo->parametros['ie']);
        $this->assertEquals('2026-07-01', $vinculo->parametros['periodo_inicial']);

        $execucao = $service->enfileirarManual($vinculo, userId: $user->id, parametros: $params);

        $this->assertEquals('2026-07-01', $execucao->periodo_inicio->toDateString());
        $this->assertEquals('2026-07-20', $execucao->periodo_fim->toDateString());
        $this->assertEquals('saida-consulente', $execucao->parametros['operacao']);
        $this->assertEquals('9876543210', $execucao->parametros['ie']);
    }

    public function test_validar_acesso_cria_vinculo_e_enfileira(): void
    {
        config(['automacao_fiscal.fake_mode' => true]);
        Queue::fake();

        $this->seed(PortaisIntegracaoSeeder::class);

        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'role' => 'admin',
        ]);
        $empresa = Empresa::factory()->create(['empresa_operadora_id' => $operadora->id]);
        $portal = PortalIntegracao::query()->where('codigo', 'ecac_rs')->firstOrFail();

        $integracao = EmpresaIntegracao::create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'portal_integracao_id' => $portal->id,
            'ativo' => true,
            'modo_autenticacao' => 'certificado_a1',
            'status_configuracao' => 'configurado',
        ]);

        $this->actingAs($user);

        $execucao = app(AutomacaoExecucaoService::class)
            ->enfileirarValidacaoAcesso($integracao, $user->id);

        $this->assertEquals('na_fila', $execucao->status);
        $this->assertTrue(
            EmpresaIntegracaoRecurso::query()
                ->where('empresa_integracao_id', $integracao->id)
                ->whereHas('portalRecurso', fn ($q) => $q->where('codigo', 'validar_acesso'))
                ->exists()
        );
    }
}
