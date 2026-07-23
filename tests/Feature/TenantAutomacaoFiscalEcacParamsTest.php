<?php

namespace Tests\Feature;

use App\Models\AutomacaoExecucao;
use App\Models\Empresa;
use App\Models\EmpresaIntegracao;
use App\Models\EmpresasOperadora;
use App\Models\PortalIntegracao;
use App\Models\PortalRecurso;
use App\Services\AutomacaoFiscal\Portais\EcacRsPortal;
use Database\Seeders\PortaisIntegracaoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

class TenantAutomacaoFiscalEcacParamsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_montar_params_normaliza_cnpj_e_ie_para_digitos(): void
    {
        $this->seed(PortaisIntegracaoSeeder::class);

        $operadora = EmpresasOperadora::factory()->create();
        $empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'cnpj' => '04.756.684/0001-07',
            'inscricao_estadual' => '135.002.8310',
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

        $execucao = AutomacaoExecucao::create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'empresa_integracao_id' => $integracao->id,
            'portal_recurso_id' => $recurso->id,
            'gatilho' => 'manual',
            'periodo_inicio' => '2026-07-01',
            'periodo_fim' => '2026-07-20',
            'status' => 'na_fila',
            'parametros' => [
                'cnpj' => '04.756.684/0001-07',
                'modelo' => 'nfe',
                'periodo_inicial' => '2026-07-01',
                'periodo_final' => '2026-07-20',
                'operacao' => 'saida-consulente',
            ],
        ]);
        $execucao->setRelation('empresa', $empresa);
        $execucao->setRelation('portalRecurso', $recurso);

        $portalDriver = app(EcacRsPortal::class);
        $method = new ReflectionMethod(EcacRsPortal::class, 'montarParams');
        $method->setAccessible(true);
        $params = $method->invoke($portalDriver, $execucao, 'extract-nfe-nfce', 'nfe_emitidas');

        $this->assertSame('04756684000107', $params['cnpj']);
        $this->assertSame('1350028310', $params['ie']);
        $this->assertSame('2026-07-01', $params['periodoInicial']);
        $this->assertSame('2026-07-20', $params['periodoFinal']);
    }
}
