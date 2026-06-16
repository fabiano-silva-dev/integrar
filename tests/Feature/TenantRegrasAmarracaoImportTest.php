<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\PlanoConta;
use App\Models\RegraAmarracaoDescricao;
use App\Models\User;
use App\Services\Importacao\ImportadorRegrasAmarracaoService;
use App\Services\Importacao\LeitorArquivoTabularService;
use App\Services\Importacao\ValidadorRegraAmarracaoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TenantRegrasAmarracaoImportTest extends TestCase
{
    use DatabaseTransactions;

    private EmpresasOperadora $operadora;
    private Empresa $empresa;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operadora = EmpresasOperadora::factory()->create();
        $this->empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
        ]);
        $this->user = User::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
        ]);
    }

    public function test_importa_regras_de_csv(): void
    {
        $this->actingAs($this->user);

        $csv = implode("\n", [
            'layout;palavra_chave;parte_digitavel;tipo_busca;conta_contrapartida;centro_custo;prioridade;descricao;ativo',
            'ofx;PAGAMENTO PIX;;starts_with;21001;;10;Recebimento;1',
            'ofx;TARIFA;;contains;43005;;5;Tarifa;1',
        ]);

        $path = sys_get_temp_dir() . '/regras_import_test.csv';
        file_put_contents($path, $csv);

        $leitor = new LeitorArquivoTabularService();
        $dados = $leitor->ler($path, 'csv');

        $service = new ImportadorRegrasAmarracaoService();
        $mapeamento = $service->sugerirMapeamento($dados['colunas']);
        $preview = $service->analisar($dados['linhas'], $mapeamento, $this->empresa->id, 'adicionar_atualizar');

        $this->assertEquals(2, $preview['regras_validas']);
        $this->assertEquals(2, $preview['regras_novas']);

        $resultado = $service->persistir($preview['regras'], $this->empresa->id, 'adicionar_atualizar');

        $this->assertEquals(2, $resultado['novas']);
        $this->assertEquals(2, RegraAmarracaoDescricao::where('empresa_id', $this->empresa->id)->count());

        unlink($path);
    }

    public function test_somente_adicionar_ignora_regras_existentes(): void
    {
        $this->actingAs($this->user);

        RegraAmarracaoDescricao::create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'layout_avancado' => 'ofx',
            'palavra_chave' => 'PIX',
            'tipo_busca' => 'starts_with',
            'conta_contrapartida' => '99999',
            'ativo' => true,
            'prioridade' => 0,
        ]);

        $linhas = [
            [
                'layout' => 'ofx',
                'palavra_chave' => 'PIX',
                'parte_digitavel' => '',
                'tipo_busca' => 'starts_with',
                'conta_contrapartida' => '21001',
                'centro_custo' => '',
                'prioridade' => '0',
                'descricao' => 'Novo histórico',
                'ativo' => '1',
            ],
        ];

        $mapeamento = [
            'layout' => 'layout',
            'palavra_chave' => 'palavra_chave',
            'parte_digitavel' => 'parte_digitavel',
            'tipo_busca' => 'tipo_busca',
            'conta_contrapartida' => 'conta_contrapartida',
            'centro_custo' => 'centro_custo',
            'prioridade' => 'prioridade',
            'descricao' => 'descricao',
            'ativo' => 'ativo',
        ];

        $service = new ImportadorRegrasAmarracaoService();
        $preview = $service->analisar($linhas, $mapeamento, $this->empresa->id, 'somente_adicionar');

        $this->assertEquals(0, $preview['regras_validas']);
        $this->assertEquals(1, $preview['regras_ignoradas']);
    }

    public function test_aviso_quando_conta_nao_existe_no_plano(): void
    {
        $this->actingAs($this->user);

        PlanoConta::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'codigo' => '1.1.01.001',
            'codigo_reduzido' => '101',
            'descricao' => 'Caixa',
            'ativo' => true,
        ]);

        $validador = new ValidadorRegraAmarracaoService();
        $this->assertNull($validador->avisoContaContrapartida('101', $this->empresa->id));
        $this->assertNotNull($validador->avisoContaContrapartida('99999', $this->empresa->id));
    }

    public function test_substituir_layout_remove_regras_do_layout(): void
    {
        $this->actingAs($this->user);

        RegraAmarracaoDescricao::create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'layout_avancado' => 'ofx',
            'palavra_chave' => 'ANTIGA',
            'tipo_busca' => 'starts_with',
            'ativo' => true,
            'prioridade' => 0,
        ]);

        $linhas = [
            [
                'layout' => 'ofx',
                'palavra_chave' => 'NOVA',
                'parte_digitavel' => '',
                'tipo_busca' => 'contains',
                'conta_contrapartida' => '100',
                'centro_custo' => '',
                'prioridade' => '0',
                'descricao' => '',
                'ativo' => '1',
            ],
        ];

        $mapeamento = array_combine(
            array_keys(ImportadorRegrasAmarracaoService::CAMPOS),
            array_keys(ImportadorRegrasAmarracaoService::CAMPOS)
        );

        $service = new ImportadorRegrasAmarracaoService();
        $preview = $service->analisar($linhas, $mapeamento, $this->empresa->id, 'substituir_layout');
        $service->persistir($preview['regras'], $this->empresa->id, 'substituir_layout');

        $regras = RegraAmarracaoDescricao::where('empresa_id', $this->empresa->id)->get();
        $this->assertCount(1, $regras);
        $this->assertEquals('NOVA', $regras->first()->palavra_chave);
    }
}
