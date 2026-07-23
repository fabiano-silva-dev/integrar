<?php

namespace Tests\Feature;

use App\Jobs\AutomacaoFiscal\ExecutarAutomacaoPortalJob;
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

class TenantAutomacaoFiscalFilaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_execucao_manual_despacha_job_e_driver_fake_conclui(): void
    {
        config(['automacao_fiscal.fake_mode' => true, 'queue.default' => 'sync']);

        $this->seed(PortaisIntegracaoSeeder::class);

        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'role' => 'admin',
        ]);
        $empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
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

        $vinculo = EmpresaIntegracaoRecurso::create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_integracao_id' => $integracao->id,
            'portal_recurso_id' => $recurso->id,
            'ativo' => true,
        ]);

        $this->actingAs($user);

        $service = app(AutomacaoExecucaoService::class);
        $execucao = $service->enfileirarManual($vinculo, userId: $user->id);

        $execucao->refresh();

        $this->assertEquals('sucesso', $execucao->status);
        $this->assertEquals(3, $execucao->quantidade_encontrada);
        $this->assertStringContainsString('simulada', $execucao->mensagem_usuario);
        $this->assertTrue($execucao->logs()->exists());
        $this->assertTrue($execucao->artefatos()->where('tipo', 'screenshot')->exists());
    }

    public function test_job_e_enfileirado_quando_fila_nao_e_sync(): void
    {
        config(['automacao_fiscal.fake_mode' => true]);
        Queue::fake();

        $this->seed(PortaisIntegracaoSeeder::class);

        $operadora = EmpresasOperadora::factory()->create();
        $empresa = Empresa::factory()->create(['empresa_operadora_id' => $operadora->id]);
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

        $vinculo = EmpresaIntegracaoRecurso::create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_integracao_id' => $integracao->id,
            'portal_recurso_id' => $recurso->id,
            'ativo' => true,
        ]);

        app(AutomacaoExecucaoService::class)->enfileirarManual($vinculo);

        Queue::assertPushed(ExecutarAutomacaoPortalJob::class);
    }
}
