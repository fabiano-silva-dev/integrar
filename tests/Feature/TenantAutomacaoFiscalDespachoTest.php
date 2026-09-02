<?php

namespace Tests\Feature;

use App\Jobs\AutomacaoFiscal\ExecutarAutomacaoPortalJob;
use App\Models\AgendaAutomacao;
use App\Models\AutomacaoConfiguracao;
use App\Models\AutomacaoExecucao;
use App\Models\Empresa;
use App\Models\EmpresaIntegracao;
use App\Models\EmpresaIntegracaoRecurso;
use App\Models\EmpresasOperadora;
use App\Models\PortalIntegracao;
use App\Models\PortalRecurso;
use App\Services\AutomacaoFiscal\Portais\EcacRsPortal;
use App\Services\AutomacaoFiscal\Portais\NfseNacionalPortal;
use Database\Seeders\PortaisIntegracaoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

class TenantAutomacaoFiscalDespachoTest extends TestCase
{
    use DatabaseTransactions;

    private EmpresasOperadora $operadora;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PortaisIntegracaoSeeder::class);

        $this->operadora = EmpresasOperadora::factory()->create([
            'nome_fantasia' => 'Escritório Despacho',
        ]);

        $this->empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'nome' => 'Cliente Despacho',
            'cnpj' => '04.756.684/0001-07',
        ]);

        AutomacaoConfiguracao::query()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'timezone' => 'America/Sao_Paulo',
            'periodo_padrao_dias' => 30,
            'max_execucoes_simultaneas' => 1,
            'politica_tentativas' => 3,
            'retencao_logs_dias' => 90,
            'retencao_artefatos_dias' => 30,
            'aviso_certificado_dias' => 30,
        ]);
    }

    public function test_despachar_enfileira_nfe_emitidas_com_periodo_padrao(): void
    {
        Queue::fake();

        [$agenda, $vinculo] = $this->criarVinculoComAgenda('ecac_rs', 'nfe_emitidas', [
            'modelo' => 'ambos',
            'operacao' => 'entrada-terceiros',
            'periodo_inicial' => '2020-01-01',
            'periodo_final' => '2020-01-31',
        ]);

        Artisan::call('automacoes:despachar');

        Queue::assertPushedOn('automacoes', ExecutarAutomacaoPortalJob::class);

        $execucao = AutomacaoExecucao::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $this->operadora->id)
            ->where('portal_recurso_id', $vinculo->portal_recurso_id)
            ->where('gatilho', 'agendado')
            ->firstOrFail();

        $this->assertSame('na_fila', $execucao->status);
        $this->assertSame($agenda->id, $execucao->agenda_automacao_id);
        $this->assertNotNull($execucao->periodo_inicio);
        $this->assertNotNull($execucao->periodo_fim);
        $this->assertEquals(30, (int) $execucao->periodo_inicio->diffInDays($execucao->periodo_fim));

        $vinculo->refresh();
        $this->assertNotNull($vinculo->last_run_at);
        $this->assertNotNull($vinculo->next_run_at);
        $this->assertTrue($vinculo->next_run_at->greaterThan(now()));
    }

    public function test_despachar_enfileira_nfse_emitidas(): void
    {
        Queue::fake();

        [, $vinculo] = $this->criarVinculoComAgenda('nfse_nacional', 'nfse_emitidas', [
            'periodo_inicial' => '2020-01-01',
            'periodo_final' => '2020-01-31',
        ]);

        Artisan::call('automacoes:despachar');

        Queue::assertPushedOn('automacoes', ExecutarAutomacaoPortalJob::class);

        $execucao = AutomacaoExecucao::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $this->operadora->id)
            ->where('portal_recurso_id', $vinculo->portal_recurso_id)
            ->where('gatilho', 'agendado')
            ->firstOrFail();

        $this->assertEquals(30, (int) $execucao->periodo_inicio->diffInDays($execucao->periodo_fim));
    }

    public function test_montar_params_agendado_ignora_datas_fixas_do_vinculo_ecac(): void
    {
        [, $vinculo] = $this->criarVinculoComAgenda('ecac_rs', 'nfe_emitidas', [
            'modelo' => 'ambos',
            'operacao' => 'entrada-terceiros',
            'periodo_inicial' => '2020-01-01',
            'periodo_final' => '2020-01-15',
        ]);

        $execucao = AutomacaoExecucao::create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'empresa_integracao_id' => $vinculo->empresa_integracao_id,
            'portal_recurso_id' => $vinculo->portal_recurso_id,
            'agenda_automacao_id' => $vinculo->agenda_automacao_id,
            'gatilho' => 'agendado',
            'periodo_inicio' => '2026-08-01',
            'periodo_fim' => '2026-08-31',
            'status' => 'na_fila',
            'parametros' => $vinculo->parametros,
        ]);
        $execucao->setRelation('empresa', $this->empresa);
        $execucao->setRelation('portalRecurso', $vinculo->portalRecurso);

        $method = new ReflectionMethod(EcacRsPortal::class, 'montarParams');
        $method->setAccessible(true);
        $params = $method->invoke(app(EcacRsPortal::class), $execucao, 'extract-nfe-nfce', 'nfe_emitidas');

        $this->assertSame('2026-08-01', $params['periodoInicial']);
        $this->assertSame('2026-08-31', $params['periodoFinal']);
        $this->assertSame('ambos', $params['modelo']);
        $this->assertSame('entrada-terceiros', $params['operacao']);
    }

    public function test_montar_params_agendado_ignora_datas_fixas_do_vinculo_nfse(): void
    {
        [, $vinculo] = $this->criarVinculoComAgenda('nfse_nacional', 'nfse_emitidas', [
            'periodo_inicial' => '2020-01-01',
            'periodo_final' => '2020-01-15',
        ]);

        $execucao = AutomacaoExecucao::create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'empresa_integracao_id' => $vinculo->empresa_integracao_id,
            'portal_recurso_id' => $vinculo->portal_recurso_id,
            'agenda_automacao_id' => $vinculo->agenda_automacao_id,
            'gatilho' => 'agendado',
            'periodo_inicio' => '2026-08-02',
            'periodo_fim' => '2026-09-01',
            'status' => 'na_fila',
            'parametros' => $vinculo->parametros,
        ]);
        $execucao->setRelation('empresa', $this->empresa);
        $execucao->setRelation('portalRecurso', $vinculo->portalRecurso);

        $method = new ReflectionMethod(NfseNacionalPortal::class, 'montarParams');
        $method->setAccessible(true);
        $params = $method->invoke(app(NfseNacionalPortal::class), $execucao, 'extract-nfse', 'nfse_emitidas');

        $this->assertSame('2026-08-02', $params['periodoInicial']);
        $this->assertSame('2026-09-01', $params['periodoFinal']);
        $this->assertSame('emitidas', $params['tipo']);
    }

    /**
     * @param  array<string, mixed>  $parametros
     * @return array{0: AgendaAutomacao, 1: EmpresaIntegracaoRecurso}
     */
    private function criarVinculoComAgenda(string $portalCodigo, string $recursoCodigo, array $parametros): array
    {
        $portal = PortalIntegracao::query()->where('codigo', $portalCodigo)->firstOrFail();
        $recurso = PortalRecurso::query()
            ->where('portal_integracao_id', $portal->id)
            ->where('codigo', $recursoCodigo)
            ->firstOrFail();

        $agenda = AgendaAutomacao::create([
            'empresa_operadora_id' => $this->operadora->id,
            'nome' => 'Agenda '.$recursoCodigo,
            'ativo' => true,
            'frequencia' => 'diaria',
            'horarios' => ['04:00'],
            'timezone' => 'America/Sao_Paulo',
        ]);

        $integracao = EmpresaIntegracao::create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'portal_integracao_id' => $portal->id,
            'ativo' => true,
            'modo_autenticacao' => 'certificado_a1',
            'status_configuracao' => 'configurado',
        ]);

        $vinculo = EmpresaIntegracaoRecurso::create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_integracao_id' => $integracao->id,
            'portal_recurso_id' => $recurso->id,
            'ativo' => true,
            'agenda_automacao_id' => $agenda->id,
            'next_run_at' => null,
            'parametros' => $parametros,
        ]);

        return [$agenda, $vinculo];
    }
}
