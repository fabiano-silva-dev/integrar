<?php

namespace Tests\Unit;

use App\Services\Nfse\ImportacaoPlanilha;
use App\Services\Nfse\LeitorPlanilha;
use App\Services\Nfse\PreparacaoNfse;
use Illuminate\Validation\ValidationException;
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

    public function test_excel_rejeita_formulas_sem_avaliar(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nfse-');
        $book = new Spreadsheet;
        try {
            $book->getActiveSheet()->fromArray([['Documento', 'Valor'], ['52998224725', '=1+2']]);
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            $this->expectException(ValidationException::class);
            (new LeitorPlanilha)->ler($path, 'xlsx', 1);
        } finally {
            $book->disconnectWorksheets();
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
}
