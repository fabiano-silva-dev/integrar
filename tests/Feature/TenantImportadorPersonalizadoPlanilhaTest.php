<?php

namespace Tests\Feature;

use App\Livewire\ImportadorPersonalizado;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
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
