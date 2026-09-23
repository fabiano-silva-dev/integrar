<?php

namespace Tests\Feature;

use App\Livewire\ImportadorPersonalizado;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\LayoutImportacao;
use App\Models\RegraAmarracaoImportacao;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class TenantImportadorPersonalizadoPlanilhaTest extends TestCase
{
    use DatabaseTransactions;

    private EmpresasOperadora $operadora;
    private Empresa $empresa;
    private User $user;

    /** @var list<string> */
    private array $temporarios = [];

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

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            if (is_file($arquivo)) {
                @unlink($arquivo);
            }
        }

        parent::tearDown();
    }

    public function test_cielo_pede_escolha_quando_ha_duas_tabelas(): void
    {
        $this->actingAs($this->user);
        session(['empresa_selecionada_id' => $this->empresa->id]);

        Livewire::test(ImportadorPersonalizado::class)
            ->set('arquivo', $this->uploaded($this->criarCielo(), 'Cielo.xlsx'))
            ->assertSet('step', 1)
            ->assertSet('excelAnalise.escolha_automatica', false)
            ->assertCount('excelAnalise.tabelas', 2)
            ->assertSee('Qual tabela importar')
            ->assertSee('Totalizador')
            ->assertSee('Cabeçalho na linha 7')
            ->call('selecionarTabelaExcel', 0)
            ->assertSet('excelTabelaEscolhida', 0)
            ->call('confirmarTabelaExcel')
            ->assertSet('step', 2)
            ->assertSet('colunasArquivo.0', 'Quantidade de lançamentos');
    }

    public function test_alelo_escolhe_automaticamente_a_unica_aba_com_tabela(): void
    {
        $this->actingAs($this->user);
        session(['empresa_selecionada_id' => $this->empresa->id]);

        Livewire::test(ImportadorPersonalizado::class)
            ->set('arquivo', $this->uploaded($this->criarAlelo(), 'Alelo.xlsx'))
            ->assertSet('step', 2)
            ->assertSet('excelAbaEscolhida', 'Extrato')
            ->assertSet('colunasArquivo.0', 'Nome')
            ->assertSet('colunasArquivo.2', 'Valor Bruto');
    }

    public function test_calcula_diferenca_entre_duas_colunas_no_valor_do_lancamento(): void
    {
        $componente = new ImportadorPersonalizado();
        $componente->colunasArquivo = ['Data', 'Valor Bruto', 'Valor Líquido'];
        $componente->regrasAmarracao = [[
            'tipo' => 'automatica',
            'coluna_data' => 'Data',
            'coluna_descricao' => '',
            'coluna_documento' => '',
            'colunas_valores' => [[
                'origem' => '__diferenca__',
                'coluna_inicial' => 'Valor Bruto',
                'coluna_subtrair' => 'Valor Líquido',
            ]],
            'contas_debito' => ['1.1.1'],
            'contas_credito' => ['3.1.1'],
            'historicos' => ['Taxa da operação'],
        ]];

        $resultado = $componente->processarLinha(['01/09/2026', 'R$ 150,00', 'R$ 142,50']);

        $this->assertSame('7.50', $resultado[0]['valores_multiplos'][0]['valor']);
        $this->assertSame('1.1.1', $resultado[0]['valores_multiplos'][0]['conta_debito']);
        $this->assertSame('3.1.1', $resultado[0]['valores_multiplos'][0]['conta_credito']);
    }

    public function test_mantem_compatibilidade_com_regra_antiga_de_coluna_direta(): void
    {
        $componente = new ImportadorPersonalizado();
        $componente->colunasArquivo = ['Data', 'Valor'];
        $componente->regrasAmarracao = [[
            'tipo' => 'automatica',
            'coluna_data' => 'Data',
            'coluna_descricao' => '',
            'coluna_documento' => '',
            'colunas_valores' => ['Valor'],
            'contas_debito' => ['1.1.1'],
            'contas_credito' => ['3.1.1'],
            'historicos' => ['Venda'],
        ]];

        $resultado = $componente->processarLinha(['01/09/2026', 'R$ 99,90']);

        $this->assertSame('R$ 99,90', $resultado[0]['valores_multiplos'][0]['valor']);
    }

    public function test_layouts_so_aparecem_depois_do_upload_e_trazem_as_regras_do_tipo(): void
    {
        $this->actingAs($this->user);
        session(['empresa_selecionada_id' => $this->empresa->id]);

        $layout = LayoutImportacao::create([
            'nome' => 'Alelo salvo',
            'tipo_arquivo' => 'xlsx',
            'tem_cabecalho' => true,
            'empresa_id' => $this->empresa->id,
            'user_id' => $this->user->id,
        ]);
        RegraAmarracaoImportacao::create([
            'layout_importacao_id' => $layout->id,
            'nome_regra' => 'Taxa Alelo',
            'tipo' => 'automatica',
            'coluna_data' => 'Data',
            'ativo' => true,
            'ordem' => 1,
        ]);
        LayoutImportacao::create([
            'nome' => 'Somente CSV',
            'tipo_arquivo' => 'csv',
            'tem_cabecalho' => true,
            'empresa_id' => $this->empresa->id,
            'user_id' => $this->user->id,
        ]);

        Livewire::test(ImportadorPersonalizado::class)
            ->assertDontSee('Layouts Disponíveis')
            ->set('arquivo', $this->uploaded($this->criarAlelo(), 'Alelo.xlsx'))
            ->assertSet('step', 1)
            ->assertSet('aguardandoEscolhaLayout', true)
            ->assertSee('Layouts Disponíveis')
            ->assertSee('Novo layout')
            ->assertSee('Alelo salvo')
            ->assertSee('Taxa Alelo')
            ->assertDontSee('Somente CSV')
            ->assertDontSee('Configurações do Arquivo')
            ->call('carregarLayout', $layout->id)
            ->assertSet('step', 2)
            ->assertSet('aguardandoEscolhaLayout', false)
            ->assertSee('Elas ficam salvas ao confirmar')
            ->assertDontSee('Salvar Regra')
            ->assertSet('regrasAmarracao.0.nome_regra', 'Taxa Alelo')
            ->assertSet('nomeLayout', 'Alelo salvo');
    }

    public function test_confirmar_importacao_grava_as_regras_no_layout(): void
    {
        $this->actingAs($this->user);
        session(['empresa_selecionada_id' => $this->empresa->id]);

        $csv = UploadedFile::fake()->createWithContent(
            'vendas.csv',
            "Data;Valor\n01/09/2026;10,50\n"
        );

        Livewire::test(ImportadorPersonalizado::class)
            ->set('arquivo', $csv)
            ->set('nomeLayout', 'Vendas do dia')
            ->set('regrasAmarracao', [[
                'nome_regra' => 'Venda do dia',
                'tipo' => 'automatica',
                'coluna_data' => 'Data',
                'coluna_descricao' => '',
                'coluna_documento' => '',
                'conta_debito_fixa' => '',
                'conta_credito_fixa' => '',
                'historico_fixo' => '',
                'centro_custo_fixo' => '',
                'colunas_valores' => [[
                    'origem' => 'Valor',
                    'coluna_inicial' => '',
                    'coluna_subtrair' => '',
                ]],
                'contas_debito' => ['1.1.1'],
                'contas_credito' => ['3.1.1'],
                'historicos' => ['Venda'],
            ]])
            ->set('regraAtual.nome_regra', 'Rascunho ainda aberto')
            ->set('regraAtual.coluna_data', 'Data')
            ->call('avancarParaPrevia')
            ->assertSet('step', 3)
            ->assertSee('O que fica salvo')
            ->assertSee('Venda do dia')
            ->assertSee('Rascunho ainda aberto')
            ->assertSee('Layout Vendas do dia (CSV)')
            ->call('confirmarImportacao')
            ->assertRedirect(route('importacoes'));

        $layout = LayoutImportacao::where('nome', 'Vendas do dia')->where('empresa_id', $this->empresa->id)->first();
        $this->assertNotNull($layout);
        $this->assertSame(
            ['Venda do dia', 'Rascunho ainda aberto'],
            RegraAmarracaoImportacao::where('layout_importacao_id', $layout->id)->orderBy('ordem')->pluck('nome_regra')->all()
        );
    }

    public function test_confirmar_sem_regras_mantem_as_que_ja_existiam_no_layout(): void
    {
        $this->actingAs($this->user);
        session(['empresa_selecionada_id' => $this->empresa->id]);

        $layout = LayoutImportacao::create([
            'nome' => 'Layout antigo',
            'tipo_arquivo' => 'csv',
            'delimitador' => ';',
            'tem_cabecalho' => true,
            'empresa_id' => $this->empresa->id,
            'user_id' => $this->user->id,
        ]);
        RegraAmarracaoImportacao::create([
            'layout_importacao_id' => $layout->id,
            'nome_regra' => 'Regra antiga',
            'tipo' => 'manual',
            'conta_debito_fixa' => '10',
            'ativo' => true,
            'ordem' => 1,
        ]);

        $csv = UploadedFile::fake()->createWithContent(
            'outro.csv',
            "Data;Valor\n01/09/2026;10,50\n"
        );

        Livewire::test(ImportadorPersonalizado::class)
            ->set('arquivo', $csv)
            ->set('nomeLayout', 'Layout antigo')
            ->set('regrasAmarracao', [])
            ->call('confirmarImportacao');

        $this->assertSame(
            ['Regra antiga'],
            RegraAmarracaoImportacao::where('layout_importacao_id', $layout->id)->pluck('nome_regra')->all()
        );
    }

    private function uploaded(string $caminho, string $nome): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, file_get_contents($caminho));
    }

    private function criarCielo(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Recebiveis');
        $sheet->setCellValue('B1', "Ouvidoria:\n0800 570 2288 (todas as localidades)\nAtendimento de segunda a sexta.");
        $sheet->setCellValue('A2', 'Recebíveis Detalhado - Lançamentos');
        $sheet->setCellValue('A6', 'Totalizador');
        $sheet->fromArray(['Quantidade de lançamentos', 'Valor bruto', 'Taxa/tarifa', 'Valor líquido'], null, 'A7');
        $sheet->fromArray(['3', 'R$ 100,00', '-R$ 2,00', 'R$ 98,00'], null, 'A8');
        $sheet->fromArray(['Data de pagamento', 'Data do lançamento', 'Estabelecimento', 'Valor bruto'], null, 'A10');
        $sheet->fromArray(['31/08/2026', '29/07/2026', '2780409643', '24'], null, 'A11');
        $sheet->fromArray(['31/08/2026', '30/07/2026', '2780409643', '28'], null, 'A12');

        return $this->salvar($spreadsheet, 'lw_cielo');
    }

    private function criarAlelo(): string
    {
        $spreadsheet = new Spreadsheet();
        $instrucoes = $spreadsheet->getActiveSheet();
        $instrucoes->setTitle('Instruções');
        $instrucoes->setCellValue('B2', 'Como usar esse arquivo: Na aba Extrato incluímos todas as transações. Já na aba Não Exportadas mostramos estabelecimentos sem transações.');

        $extrato = $spreadsheet->createSheet();
        $extrato->setTitle('Extrato');
        $extrato->fromArray(['Nome', 'CNPJ', 'Valor Bruto', 'Valor Líquido'], null, 'A1');
        $extrato->fromArray(['Empresa Alfa', '92299833000113', '10.50', '10.10'], null, 'A2');
        $extrato->fromArray(['Empresa Alfa', '92299833000113', '22.00', '21.20'], null, 'A3');

        $vazia = $spreadsheet->createSheet();
        $vazia->setTitle('Não Exportadas');
        $vazia->fromArray(['EC', 'Motivo'], null, 'A1');

        return $this->salvar($spreadsheet, 'lw_alelo');
    }

    private function salvar(Spreadsheet $spreadsheet, string $prefixo): string
    {
        $caminho = sys_get_temp_dir() . "/integrar_{$prefixo}_" . uniqid() . '.xlsx';
        $this->temporarios[] = $caminho;
        (new Xlsx($spreadsheet))->save($caminho);

        return $caminho;
    }
}
