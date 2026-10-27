<?php

namespace Tests\Unit;

use Tests\TestCase;

class LeitorExcelXml2003Test extends TestCase
{
    private function executarDetector(string $xml): array
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'integra_xml_');
        file_put_contents($arquivo, $xml);
        try {
            $script = base_path('scripts/detector_tabela_planilha.py');
            $comando = sprintf(
                'python3 %s %s 2>/dev/null',
                escapeshellarg($script),
                escapeshellarg($arquivo)
            );
            $saida = shell_exec($comando);
            $this->assertNotEmpty($saida);
            $dados = json_decode((string) $saida, true);
            $this->assertIsArray($dados);
            return $dados;
        } finally {
            @unlink($arquivo);
        }
    }

    public function test_reconhece_excel_2003_xml_com_entidades_html(): void
    {
        $dados = $this->executarDetector(<<<'XML'
<?xml version="1.0"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Transacoes"><Table>
<Row><Cell><Data ss:Type="String">Cart&atilde;o</Data></Cell><Cell><Data ss:Type="String">Data</Data></Cell><Cell><Data ss:Type="String">Valor</Data></Cell></Row>
<Row><Cell><Data ss:Type="String">0001</Data></Cell><Cell><Data ss:Type="String">15/09/2026</Data></Cell><Cell><Data ss:Type="Number">10.50</Data></Cell></Row>
<Row><Cell><Data ss:Type="String">0002</Data></Cell><Cell><Data ss:Type="String">16/09/2026</Data></Cell><Cell><Data ss:Type="Number">20.10</Data></Cell></Row>
</Table></Worksheet></Workbook>
XML);
        $this->assertTrue($dados['sucesso'] ?? false, $dados['mensagem'] ?? 'falha');
        $this->assertSame(['Cartão', 'Data', 'Valor'], $dados['tabelas'][0]['cabecalho']);
        $this->assertSame(2, $dados['tabelas'][0]['linhas_dados']);
    }

    public function test_arquivo_sem_transacoes_nao_retorna_erro_tecnico(): void
    {
        $dados = $this->executarDetector(<<<'XML'
<?xml version="1.0"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Transacoes"><Table>
<Row><Cell><Data ss:Type="String">Cartão</Data></Cell><Cell><Data ss:Type="String">Data</Data></Cell><Cell><Data ss:Type="String">Valor</Data></Cell></Row>
<Row><Cell ss:MergeAcross="2"><Data ss:Type="String">Nenhuma transa&ccedil;&atilde;o encontrada para estes par&acirc;metros.</Data></Cell></Row>
</Table></Worksheet></Workbook>
XML);
        $this->assertFalse($dados['sucesso'] ?? true);
        $this->assertTrue($dados['sem_movimentos'] ?? false);
        $this->assertStringContainsString('não contém transações', $dados['mensagem']);
    }
}
