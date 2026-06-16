<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\RegraAmarracaoDescricao;
use App\Models\User;
use App\Services\Importacao\ExportadorRegrasAmarracaoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TenantRegrasAmarracaoExportTest extends TestCase
{
    use DatabaseTransactions;

    private EmpresasOperadora $operadora;
    private Empresa $empresaA;
    private Empresa $empresaB;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operadora = EmpresasOperadora::factory()->create();
        $this->empresaA = Empresa::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'nome' => 'Empresa A',
        ]);
        $this->empresaB = Empresa::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'nome' => 'Empresa B',
        ]);
        $this->user = User::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
        ]);
    }

    public function test_exporta_regras_em_csv(): void
    {
        $this->criarRegra($this->empresaA->id, 'PIX RECEBIDO', '21001');

        $service = new ExportadorRegrasAmarracaoService();
        $csv = $service->exportarCsv($this->empresaA->id, 'ofx');

        $this->assertStringContainsString('palavra_chave', $csv);
        $this->assertStringContainsString('PIX RECEBIDO', $csv);
        $this->assertStringContainsString('21001', $csv);
    }

    public function test_copia_regras_entre_empresas_do_mesmo_escritorio(): void
    {
        $this->actingAs($this->user);

        $this->criarRegra($this->empresaA->id, 'TARIFA', '43005');
        $this->criarRegra($this->empresaA->id, 'PIX', '21001');

        $service = new ExportadorRegrasAmarracaoService();
        $resultado = $service->copiar(
            $this->empresaA->id,
            $this->empresaB->id,
            'ofx',
            'adicionar_atualizar'
        );

        $this->assertEquals(2, $resultado['copiadas']);
        $this->assertEquals(2, RegraAmarracaoDescricao::where('empresa_id', $this->empresaB->id)->count());
    }

    public function test_somente_adicionar_ignora_regras_ja_existentes_na_copia(): void
    {
        $this->actingAs($this->user);

        $this->criarRegra($this->empresaA->id, 'PIX', '21001');
        $this->criarRegra($this->empresaB->id, 'PIX', '99999', 'starts_with', null);

        $service = new ExportadorRegrasAmarracaoService();
        $resultado = $service->copiar(
            $this->empresaA->id,
            $this->empresaB->id,
            'ofx',
            'somente_adicionar'
        );

        $this->assertEquals(0, $resultado['copiadas']);
        $this->assertEquals(1, $resultado['ignoradas']);

        $regraDestino = RegraAmarracaoDescricao::where('empresa_id', $this->empresaB->id)
            ->where('palavra_chave', 'PIX')
            ->first();

        $this->assertEquals('99999', $regraDestino->conta_contrapartida);
    }

    public function test_tela_importar_regras_requer_autenticacao(): void
    {
        $this->get(route('regras-amarracao.importar'))->assertRedirect(route('login'));
    }

    public function test_usuario_acessa_tela_importar_regras(): void
    {
        $this->actingAs($this->user)
            ->get(route('regras-amarracao.importar'))
            ->assertOk()
            ->assertSee('Importar Regras de Amarração');
    }

    private function criarRegra(
        int $empresaId,
        string $palavraChave,
        string $contaContrapartida,
        string $tipoBusca = 'starts_with',
        ?string $parteDigitavel = null
    ): RegraAmarracaoDescricao {
        return RegraAmarracaoDescricao::create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $empresaId,
            'layout_avancado' => 'ofx',
            'palavra_chave' => $palavraChave,
            'parte_digitavel' => $parteDigitavel,
            'tipo_busca' => $tipoBusca,
            'conta_contrapartida' => $contaContrapartida,
            'ativo' => true,
            'prioridade' => 0,
        ]);
    }
}
