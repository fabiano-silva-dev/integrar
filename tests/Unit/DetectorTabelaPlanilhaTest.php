<?php

namespace Tests\Unit;

use App\Services\DetectorTabelaPlanilhaService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DetectorTabelaPlanilhaTest extends TestCase
{
    /** @var list<string> */
    private array $temporarios = [];

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            if (is_file($arquivo)) {
                @unlink($arquivo);
            }
        }

        parent::tearDown();
    }

    public function test_detecta_duas_tabelas_na_mesma_aba(): void
    {
        $arquivo = $this->criarPlanilhaCielo();
        $meta = $this->analisar($arquivo);

        $this->assertTrue($meta['sucesso'] ?? false, $meta['mensagem'] ?? 'falha');
        $this->assertFalse($meta['escolha_automatica'] ?? true);
        $this->assertSame(['Recebiveis'], $meta['abas_com_tabela']);
        $this->assertCount(2, $meta['tabelas']);

        $this->assertSame('Totalizador', $meta['tabelas'][0]['nome']);
        $this->assertSame(7, $meta['tabelas'][0]['linha_cabecalho']);
        $this->assertSame(8, $meta['tabelas'][0]['linha_inicio']);
        $this->assertSame(8, $meta['tabelas'][0]['linha_fim']);
        $this->assertSame(
            ['Quantidade de lançamentos', 'Valor bruto', 'Taxa/tarifa', 'Valor líquido'],
            $meta['tabelas'][0]['cabecalho']
        );

        $this->assertSame(10, $meta['tabelas'][1]['linha_cabecalho']);
        $this->assertSame(11, $meta['tabelas'][1]['linha_inicio']);
        $this->assertSame(13, $meta['tabelas'][1]['linha_fim']);
        $this->assertSame(3, $meta['tabelas'][1]['linhas_dados']);
        $this->assertSame('Data de pagamento', $meta['tabelas'][1]['cabecalho'][0]);
    }

    public function test_ignora_abas_sem_tabela_e_escolhe_automaticamente(): void
    {
        $arquivo = $this->criarPlanilhaAlelo();
        $meta = $this->analisar($arquivo);

        $this->assertTrue($meta['sucesso'] ?? false, $meta['mensagem'] ?? 'falha');
        $this->assertTrue($meta['escolha_automatica'] ?? false);
        $this->assertSame(['Extrato'], $meta['abas_com_tabela']);
        $this->assertSame('Extrato', $meta['aba_escolhida']);
        $this->assertCount(1, $meta['tabelas']);
        $this->assertSame(['Nome', 'CNPJ', 'Valor Bruto', 'Valor Líquido'], $meta['tabelas'][0]['cabecalho']);
        $this->assertSame(2, $meta['tabelas'][0]['linhas_dados']);
    }

    public function test_nao_escolhe_automaticamente_quando_ha_duas_abas_com_tabela(): void
    {
        $arquivo = $this->criarPlanilhaDuasAbas();
        $meta = $this->analisar($arquivo);

        $this->assertTrue($meta['sucesso'] ?? false, $meta['mensagem'] ?? 'falha');
        $this->assertFalse($meta['escolha_automatica'] ?? true);
        $this->assertSame(['Vendas', 'Ajustes'], $meta['abas_com_tabela']);
        $this->assertCount(2, $meta['tabelas']);
    }

    public function test_service_extrai_tabela_escolhida(): void
    {
        $arquivo = $this->criarPlanilhaCielo();
        $saida = sys_get_temp_dir() . '/integrar_planilha_' . uniqid() . '.csv';
        $this->temporarios[] = $saida;

        $resultado = (new DetectorTabelaPlanilhaService())->extrair($arquivo, 'Recebiveis', 1, $saida);

        $this->assertTrue($resultado['sucesso'] ?? false, $resultado['erro'] ?? 'falha');
        $this->assertFileExists($saida);
        $this->assertSame('Data de pagamento', $resultado['cabecalho'][0] ?? null);
        $this->assertSame(3, $resultado['linhas_dados']);

        $linhas = array_map(
            fn ($linha) => str_getcsv($linha),
            file($saida, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        );
        $this->assertSame('Data de pagamento', $linhas[0][0]);
        $this->assertSame('31/08/2026', $linhas[1][0]);
        $this->assertCount(4, $linhas);
    }

    private function analisar(string $arquivo): array
    {
        $script = base_path('scripts/detector_tabela_planilha.py');
        $this->assertFileExists($script);
        $this->assertFileExists($arquivo);

        $comando = sprintf(
            'python3 %s %s 2>/dev/null',
            escapeshellarg($script),
            escapeshellarg($arquivo)
        );

        $json = shell_exec($comando);
        $this->assertNotEmpty($json, 'Detector Python não retornou JSON');

        $dados = json_decode((string) $json, true);
        $this->assertIsArray($dados);

        return $dados;
    }

    private function criarPlanilhaCielo(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Recebiveis');

        $sheet->setCellValue('B1', "Ouvidoria:\n0800 570 2288 (todas as localidades)\nAtendimento de segunda a sexta.");
        $sheet->setCellValue('C1', "Central de Relacionamento\n4002 5472");
        $sheet->setCellValue('A2', 'Recebíveis Detalhado - Lançamentos');
        $sheet->setCellValue('A4', "Filtros:\nData de pagamento: 31/08/2026 à 31/08/2026\nEstabelecimento: Todos");
        $sheet->setCellValue('A6', 'Totalizador');
        $sheet->fromArray(['Quantidade de lançamentos', 'Valor bruto', 'Taxa/tarifa', 'Valor líquido'], null, 'A7');
        $sheet->fromArray(['3', 'R$ 100,00', '-R$ 2,00', 'R$ 98,00'], null, 'A8');
        $sheet->fromArray([
            'Data de pagamento',
            'Data do lançamento',
            'Estabelecimento',
            'Tipo de lançamento',
            'Valor bruto',
        ], null, 'A10');
        $sheet->fromArray(['31/08/2026', '29/07/2026', '2780409643', 'Venda crédito', '24'], null, 'A11');
        $sheet->fromArray(['31/08/2026', '29/07/2026', '2780409643', 'Venda crédito', '28'], null, 'A12');
        $sheet->fromArray(['31/08/2026', '30/07/2026', '2780409643', 'Venda débito', '15.5'], null, 'A13');

        return $this->salvar($spreadsheet, 'cielo');
    }

    private function criarPlanilhaAlelo(): string
    {
        $spreadsheet = new Spreadsheet();

        $instrucoes = $spreadsheet->getActiveSheet();
        $instrucoes->setTitle('Instruções');
        $instrucoes->setCellValue('B2', 'Como usar esse arquivo: Na aba Extrato incluímos todas as transações dos estabelecimentos selecionados. Já na aba Não Exportadas mostramos os estabelecimentos sem transações.');

        $extrato = $spreadsheet->createSheet();
        $extrato->setTitle('Extrato');
        $extrato->fromArray(['Nome', 'CNPJ', 'Valor Bruto', 'Valor Líquido'], null, 'A1');
        $extrato->fromArray(['Empresa Alfa', '92299833000113', '10.50', '10.10'], null, 'A2');
        $extrato->fromArray(['Empresa Alfa', '92299833000113', '22.00', '21.20'], null, 'A3');

        $vazia = $spreadsheet->createSheet();
        $vazia->setTitle('Não Exportadas');
        $vazia->fromArray(['EC', 'Motivo'], null, 'A1');

        return $this->salvar($spreadsheet, 'alelo');
    }

    private function criarPlanilhaDuasAbas(): string
    {
        $spreadsheet = new Spreadsheet();

        $vendas = $spreadsheet->getActiveSheet();
        $vendas->setTitle('Vendas');
        $vendas->fromArray(['Data', 'Descricao', 'Valor'], null, 'A1');
        $vendas->fromArray(['01/08/2026', 'Venda 1', '10'], null, 'A2');
        $vendas->fromArray(['02/08/2026', 'Venda 2', '20'], null, 'A3');

        $ajustes = $spreadsheet->createSheet();
        $ajustes->setTitle('Ajustes');
        $ajustes->fromArray(['Data', 'Historico', 'Valor'], null, 'A1');
        $ajustes->fromArray(['03/08/2026', 'Ajuste 1', '5'], null, 'A2');

        return $this->salvar($spreadsheet, 'duas_abas');
    }

    private function salvar(Spreadsheet $spreadsheet, string $prefixo): string
    {
        $caminho = sys_get_temp_dir() . "/integrar_{$prefixo}_" . uniqid() . '.xlsx';
        $this->temporarios[] = $caminho;
        (new Xlsx($spreadsheet))->save($caminho);

        return $caminho;
    }
}
