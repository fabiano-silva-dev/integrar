<?php

namespace Tests\Feature;

use App\Livewire\AutomacaoFiscal\ExecutarConsultaFiscal;
use App\Models\AutomacaoConsultaSalva;
use App\Models\Empresa;
use App\Models\EmpresaIntegracao;
use App\Models\EmpresasOperadora;
use App\Models\PortalIntegracao;
use App\Models\PortalRecurso;
use App\Models\User;
use Database\Seeders\PortaisIntegracaoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class TenantAutomacaoFiscalModeloConsultaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sugere_nome_quando_nao_existe_modelo_com_os_filtros(): void
    {
        config(['automacao_fiscal.fake_mode' => true]);
        $this->seed(PortaisIntegracaoSeeder::class);

        [$user, $empresa, $integracao, $recurso] = $this->cenarioNfseRecebidas();

        Livewire::actingAs($user)
            ->test(ExecutarConsultaFiscal::class)
            ->set('empresa_id', (string) $empresa->id)
            ->set('empresa_integracao_id', (string) $integracao->id)
            ->set('tipo_consulta', 'extrato_nfse_recebidas')
            ->set('periodo_modo', 'mes_anterior')
            ->assertSet('portal_recurso_id', (string) $recurso->id)
            ->assertSet('consulta_salva_id', '')
            ->assertSet('nome_consulta_salva', 'NFS-e recebidas — mês anterior');
    }

    public function test_seleciona_modelo_existente_com_mesmos_parametros(): void
    {
        config(['automacao_fiscal.fake_mode' => true]);
        $this->seed(PortaisIntegracaoSeeder::class);

        [$user, $empresa, $integracao, $recurso] = $this->cenarioNfseRecebidas();

        $preset = AutomacaoConsultaSalva::create([
            'empresa_id' => $empresa->id,
            'empresa_integracao_id' => $integracao->id,
            'portal_recurso_id' => $recurso->id,
            'nome' => 'Competência mês anterior recebidas',
            'parametros' => [
                'periodo_modo' => 'mes_anterior',
                'busca' => '',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(ExecutarConsultaFiscal::class)
            ->set('empresa_id', (string) $empresa->id)
            ->set('empresa_integracao_id', (string) $integracao->id)
            ->set('tipo_consulta', 'extrato_nfse_recebidas')
            ->set('periodo_modo', 'mes_anterior')
            ->assertSet('consulta_salva_id', (string) $preset->id)
            ->assertSet('nome_consulta_salva', 'Competência mês anterior recebidas');
    }

    public function test_alterar_periodo_atualiza_sugestao_e_desmarca_modelo(): void
    {
        config(['automacao_fiscal.fake_mode' => true]);
        $this->seed(PortaisIntegracaoSeeder::class);

        [$user, $empresa, $integracao, $recurso] = $this->cenarioNfseRecebidas();

        $preset = AutomacaoConsultaSalva::create([
            'empresa_id' => $empresa->id,
            'empresa_integracao_id' => $integracao->id,
            'portal_recurso_id' => $recurso->id,
            'nome' => 'Competência mês anterior recebidas',
            'parametros' => [
                'periodo_modo' => 'mes_anterior',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(ExecutarConsultaFiscal::class)
            ->set('empresa_id', (string) $empresa->id)
            ->set('empresa_integracao_id', (string) $integracao->id)
            ->set('tipo_consulta', 'extrato_nfse_recebidas')
            ->set('periodo_modo', 'mes_anterior')
            ->assertSet('consulta_salva_id', (string) $preset->id)
            ->set('periodo_modo', 'mes_atual')
            ->assertSet('consulta_salva_id', '')
            ->assertSet('nome_consulta_salva', 'NFS-e recebidas — mês atual');
    }

    /**
     * @return array{0: User, 1: Empresa, 2: EmpresaIntegracao, 3: PortalRecurso}
     */
    private function cenarioNfseRecebidas(): array
    {
        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'role' => 'admin',
        ]);
        $empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'cnpj' => '39706780000100',
        ]);

        $portal = PortalIntegracao::query()->where('codigo', 'nfse_nacional')->firstOrFail();
        $recurso = PortalRecurso::query()
            ->where('portal_integracao_id', $portal->id)
            ->where('codigo', 'nfse_recebidas')
            ->firstOrFail();

        $integracao = EmpresaIntegracao::create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'portal_integracao_id' => $portal->id,
            'ativo' => true,
            'modo_autenticacao' => 'certificado_a1',
            'status_configuracao' => 'configurado',
        ]);

        return [$user, $empresa, $integracao, $recurso];
    }
}
