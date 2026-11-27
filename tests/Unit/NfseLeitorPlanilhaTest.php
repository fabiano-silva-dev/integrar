<?php

namespace Tests\Unit;

use App\Services\Nfse\ImportacaoPlanilha;
use App\Services\Nfse\LeitorPlanilha;
use App\Services\Nfse\PreparacaoNfse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class NfseLeitorPlanilhaTest extends TestCase
{
    public function test_csv_preserva_zeros_e_detecta_mudanca_de_cabecalho(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nfse-');
        try {
            file_put_contents($path, "\xEF\xBB\xBFRelatório\nDocumento;Valor;Serviço\n01234567890;1.234,56;S1\n");
            $leitor = new LeitorPlanilha;
            $a = $leitor->ler($path, 'csv', 2);
            $this->assertSame(['Documento', 'Valor', 'Serviço'], $a['colunas']);
            $this->assertSame('01234567890', $a['linhas'][3][0]);
            file_put_contents($path, "Relatório\nValor;Documento;Serviço\n1.234,56;01234567890;S1\n");
            $b = $leitor->ler($path, 'csv', 2);
            $this->assertNotSame($a['assinatura'], $b['assinatura']);
        } finally {
            unlink($path);
        }
    }

    public function test_excel_reconhece_formula_sem_executar(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nfse-');
        $book = new Spreadsheet;
        try {
            $book->getActiveSheet()->fromArray([['Documento', 'Valor'], ['52998224725', '=1+2']]);
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            $resultado = (new LeitorPlanilha)->ler($path, 'xlsx', 1);
            $this->assertTrue($resultado['formulas'][2][1]);
            $this->assertNotSame('3', $resultado['linhas'][2][1]);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_excel_detecta_colunas_e_exclui_total_com_formula(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nfse-');
        $book = new Spreadsheet;
        try {
            $book->getActiveSheet()->setTitle('Clientes')->fromArray([
                ['Relatório mensal'],
                ['Nome completo', 'Endereço completo', 'CPF', 'Valor pago'],
                ['Cliente A', 'Endereço fictício A', '529.982.247-25', 934.16],
                ['Cliente B', 'Endereço fictício B', '111.444.777-35', 200],
                ['Total', null, null, '=SUM(D3:D4)'],
            ]);
            (new Xlsx($book))->save($path);
            $r = (new LeitorPlanilha)->detectar($path, 'xlsx');
            $this->assertSame(2, $r['cabecalho']);
            $this->assertSame('Clientes', $r['nome_aba']);
            $this->assertSame([5], $r['ignoradas']);
            $this->assertSame([3, 4], array_keys($r['linhas']));
            $this->assertSame('934.16', $r['linhas'][3][3]);
            $this->assertTrue($r['numericas'][3][3]);
            $this->assertSame(2, $r['sugestoes']['coluna_tomador']);
            $this->assertSame(3, $r['sugestoes']['coluna_valor']);
            $this->assertSame(0, $r['sugestoes']['coluna_nome']);
            $this->assertSame(1, $r['sugestoes']['coluna_endereco']);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_csv_detecta_separador_e_cabecalho(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nfse-');
        try {
            file_put_contents($path, "Relatório\nNome,CPF,Valor pago\nCliente A,52998224725,200\nTotal,,200\n");
            $r = (new LeitorPlanilha)->detectar($path, 'csv');
            $this->assertSame(',', $r['delimitador']);
            $this->assertSame(2, $r['cabecalho']);
            $this->assertSame([4], $r['ignoradas']);
            $this->assertCount(1, $r['linhas']);
        } finally {
            unlink($path);
        }
    }

    public function test_valores_usam_decimais_exatos_e_nao_adivinham_formato(): void
    {
        $service = new ImportacaoPlanilha(new PreparacaoNfse, new LeitorPlanilha);
        $this->assertSame('1234.56', $service->normalizarValor('1.234,56', 'br'));
        $this->assertSame('1.20', $service->normalizarValor('1.2', 'decimal'));
        $this->assertSame('0.01', $service->normalizarValor('0,01', 'br'));
        $this->assertNull($service->normalizarValor('1234.56', 'br'));
        $this->assertNull($service->normalizarValor('1,234', 'br'));
        $this->assertNull($service->normalizarValor('-5', 'decimal'));
        $this->assertNull($service->normalizarValor('0,00', 'br'));
    }

    public function test_excel_lista_abas_reais_e_detecta_cabecalho_da_aba_escolhida(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nfse-');
        $book = new Spreadsheet;
        try {
            $book->getActiveSheet()->setTitle('Janeiro')->fromArray([['CPF', 'Valor'], ['52998224725', 100]]);
            $book->createSheet()->setTitle('Fevereiro')->fromArray([['Relatório'], ['Nome', 'CPF', 'Valor'], ['Cliente B', '11144477735', 200]]);
            (new Xlsx($book))->save($path);
            $leitor = new LeitorPlanilha;
            $this->assertSame(['Janeiro', 'Fevereiro'], $leitor->abas($path, 'xlsx'));
            $r = $leitor->detectar($path, 'xlsx', 1);
            $this->assertSame(1, $r['aba']);
            $this->assertSame(2, $r['cabecalho']);
            $this->assertSame('Fevereiro', $r['nome_aba']);
            $this->assertSame(['Janeiro', 'Fevereiro'], $r['abas']);
            $this->assertSame('200', $r['linhas'][3][2]);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_formatacao_brasileira_preserva_centavos_sem_float(): void
    {
        $this->assertSame('2.000,00', ImportacaoPlanilha::formatarValorBr('2000'));
        $this->assertSame('934,16', ImportacaoPlanilha::formatarValorBr('934.16'));
        $this->assertSame('1.234,50', ImportacaoPlanilha::formatarValorBr('1234.5'));
        $this->assertSame('9.999.999.999.999,99', ImportacaoPlanilha::formatarValorBr('9999999999999.99'));
        $this->assertSame('inválido', ImportacaoPlanilha::formatarValorBr('inválido'));
    }

}
