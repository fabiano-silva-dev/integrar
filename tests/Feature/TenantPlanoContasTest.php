<?php

namespace Tests\Feature;

use App\Livewire\GerenciadorPlanoContas;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\PlanoConta;
use App\Models\User;
use App\Services\Importacao\ExtratorPlanoContasPdfService;
use App\Services\Importacao\ImportadorPlanoContasService;
use App\Services\Importacao\LeitorArquivoTabularService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class TenantPlanoContasTest extends TestCase
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

    public function test_importa_contas_de_csv_com_mapeamento(): void
    {
        $this->actingAs($this->user);

        $csv = implode("\n", [
            'codigo;descricao;tipo;natureza',
            '1;ATIVO;sintetica;devedora',
            '1.1.01.001;CAIXA;analitica;devedora',
        ]);

        $path = sys_get_temp_dir() . '/plano_contas_test.csv';
        file_put_contents($path, $csv);

        $leitor = new LeitorArquivoTabularService();
        $dados = $leitor->ler($path, 'csv');

        $service = new ImportadorPlanoContasService();
        $mapeamento = $service->sugerirMapeamento($dados['colunas']);
        $preview = $service->analisar($dados['linhas'], $mapeamento, $this->empresa->id, 'adicionar_atualizar');

        $this->assertEquals(2, $preview['contas_validas']);
        $this->assertEquals(2, $preview['contas_novas']);

        $importacao = $service->persistir(
            $preview['contas'],
            $this->empresa->id,
            'adicionar_atualizar',
            'teste.csv',
            'csv'
        );

        $this->assertEquals(2, $importacao->contas_novas);
        $this->assertEquals(2, PlanoConta::where('empresa_id', $this->empresa->id)->count());
        $this->assertDatabaseHas('plano_contas', [
            'empresa_id' => $this->empresa->id,
            'codigo' => '1.1.01.001',
            'descricao' => 'CAIXA',
        ]);

        unlink($path);
    }

    public function test_somente_adicionar_ignora_contas_existentes(): void
    {
        $this->actingAs($this->user);

        PlanoConta::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'codigo' => '1',
            'descricao' => 'ATIVO ANTIGO',
        ]);

        $linhas = [
            ['codigo' => '1', 'descricao' => 'ATIVO NOVO'],
            ['codigo' => '2', 'descricao' => 'PASSIVO'],
        ];

        $service = new ImportadorPlanoContasService();
        $mapeamento = [
            'codigo' => 'codigo',
            'classificacao' => '',
            'codigo_reduzido' => '',
            'descricao' => 'descricao',
            'tipo' => '',
            'natureza' => '',
            'nivel' => '',
            'codigo_pai' => '',
            'aceita_lancamento' => '',
        ];

        $preview = $service->analisar($linhas, $mapeamento, $this->empresa->id, 'somente_adicionar');

        $this->assertEquals(1, $preview['contas_validas']);
        $this->assertEquals(1, $preview['contas_ignoradas']);
        $this->assertEquals('2', $preview['contas'][0]['codigo']);
    }

    public function test_classificacao_repetida_nao_gera_codigo_duplicado(): void
    {
        $this->actingAs($this->user);

        $linhas = [
            ['codigo' => '742', 'classificacao' => '1.1.2.01.001', 'descricao' => 'Conta A', 'tipo' => 'analitica', 'nivel' => '5'],
            ['codigo' => '743', 'classificacao' => '1.1.2.01.001', 'descricao' => 'Conta B', 'tipo' => 'analitica', 'nivel' => '5'],
        ];

        $service = new ImportadorPlanoContasService();
        $mapeamento = [
            'codigo' => 'codigo',
            'classificacao' => 'classificacao',
            'codigo_reduzido' => '',
            'descricao' => 'descricao',
            'tipo' => 'tipo',
            'nivel' => 'nivel',
            'natureza' => '',
            'codigo_pai' => '',
            'aceita_lancamento' => '',
        ];

        $preview = $service->analisar($linhas, $mapeamento, $this->empresa->id, 'validar_apenas');

        $this->assertEquals(2, $preview['contas_validas']);
        $this->assertEquals(0, $preview['linhas_erro']);
    }

    public function test_importa_pdf_dominio_sem_erro_de_classificacao_duplicada(): void
    {
        $this->actingAs($this->user);

        $path = base_path('docs/plano-contas-dominio.pdf');
        $this->assertFileExists($path);

        $extrator = new ExtratorPlanoContasPdfService();
        $dados = $extrator->extrairDominio($path);

        $service = new ImportadorPlanoContasService();
        $mapeamento = $service->sugerirMapeamento($dados['colunas']);
        foreach ($dados['colunas'] as $coluna) {
            if (array_key_exists($coluna, $mapeamento)) {
                $mapeamento[$coluna] = $coluna;
            }
        }

        $preview = $service->analisar($dados['linhas'], $mapeamento, $this->empresa->id, 'validar_apenas');

        $this->assertGreaterThan(600, $preview['contas_validas']);
        $this->assertEquals(0, collect($preview['erros'])->where('mensagem', 'like', 'Código duplicado%')->count());
    }

    public function test_filtro_por_classificacao_de_conta_sintetica(): void
    {
        $this->actingAs($this->user);

        PlanoConta::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'codigo' => '3',
            'classificacao' => '1.1.1',
            'descricao' => 'DISPONÍVEL',
            'tipo' => 'sintetica',
            'nivel' => 3,
        ]);
        PlanoConta::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'codigo' => '5',
            'classificacao' => '1.1.1.01.001',
            'descricao' => 'CAIXA GERAL',
            'tipo' => 'analitica',
            'nivel' => 5,
        ]);
        PlanoConta::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'codigo' => '12',
            'classificacao' => '1.1.2',
            'descricao' => 'CLIENTES',
            'tipo' => 'sintetica',
            'nivel' => 3,
        ]);

        $component = Livewire::test(GerenciadorPlanoContas::class)
            ->set('empresa_id', $this->empresa->id)
            ->set('filtroClassificacao', '1.1.1');

        $contas = $component->viewData('contas');
        $nomes = collect($contas->items())->pluck('descricao')->all();

        $this->assertContains('DISPONÍVEL', $nomes);
        $this->assertContains('CAIXA GERAL', $nomes);
        $this->assertNotContains('CLIENTES', $nomes);
        $this->assertCount(2, $nomes);
    }

    public function test_contas_mesma_classificacao_ordenam_por_descricao(): void
    {
        $this->actingAs($this->user);

        PlanoConta::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'codigo' => '641',
            'classificacao' => '2.1.3.01.001',
            'descricao' => 'HDI SEGUROS SA',
            'tipo' => 'analitica',
            'nivel' => 5,
        ]);
        PlanoConta::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'codigo' => '663',
            'classificacao' => '2.1.3.01.001',
            'descricao' => 'ESSOR SEGUROS SA',
            'tipo' => 'analitica',
            'nivel' => 5,
        ]);

        $component = Livewire::test(GerenciadorPlanoContas::class)
            ->set('empresa_id', $this->empresa->id)
            ->set('filtroClassificacao', '2.1.3.01.001');

        $nomes = collect($component->viewData('contas')->items())->pluck('descricao')->values()->all();

        $this->assertSame(['ESSOR SEGUROS SA', 'HDI SEGUROS SA'], $nomes);
    }

    public function test_tela_plano_contas_requer_autenticacao(): void
    {
        $this->get(route('plano-contas'))->assertRedirect(route('login'));
    }

    public function test_usuario_autenticado_acessa_plano_contas(): void
    {
        $this->actingAs($this->user)
            ->get(route('plano-contas'))
            ->assertOk();
    }
}
