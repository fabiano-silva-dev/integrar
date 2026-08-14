<?php

namespace Tests\Unit;

use App\Services\ExtratorTabelaPdfService;
use Tests\TestCase;

class ExtratorTabelaPdfTest extends TestCase
{
    private function extrair(string $pdf, int $indice = 0, bool $ignorarTotais = true): array
    {
        $script = base_path('scripts/extrator_tabela_pdf.py');
        $this->assertFileExists($script);
        $this->assertFileExists($pdf);

        $saida = sys_get_temp_dir() . '/integrar_pdf_tabela_' . uniqid() . '.csv';
        $comando = sprintf(
            'python3 %s %s %s --indice %d --ignorar-totais %d 2>/dev/null',
            escapeshellarg($script),
            escapeshellarg($pdf),
            escapeshellarg($saida),
            $indice,
            $ignorarTotais ? 1 : 0
        );

        $json = shell_exec($comando);
        $this->assertNotEmpty($json, 'Extrator Python não retornou JSON');

        $dados = json_decode((string) $json, true);
        $this->assertIsArray($dados);

        return ['meta' => $dados, 'csv' => $saida];
    }

    public function test_extrai_tabela_sem_bordas(): void
    {
        $resultado = $this->extrair(base_path('tests/fixtures/pdf/relatorio_tabela_sem_bordas.pdf'));
        $meta = $resultado['meta'];

        $this->assertTrue($meta['sucesso'] ?? false, $meta['mensagem'] ?? 'falha');
        $this->assertSame('cluster', $meta['estrategia']);
        $this->assertSame(['Forma de Pagamento', 'Valor Recebido', '%'], $meta['cabecalho']);
        $this->assertSame(5, $meta['linhas_dados']);
        $this->assertSame(['A PRAZO', '9.839,01', '25,37'], $meta['tabelas'][0]['amostra'][0]);
        $this->assertFileExists($resultado['csv']);

        $linhas = file($resultado['csv'], FILE_IGNORE_NEW_LINES);
        $this->assertCount(6, $linhas);
        @unlink($resultado['csv']);
    }

    public function test_une_paginas_de_tabela_html(): void
    {
        $resultado = $this->extrair(base_path('tests/fixtures/pdf/tabela_html_multipagina.pdf'));
        $meta = $resultado['meta'];

        $this->assertTrue($meta['sucesso'] ?? false, $meta['mensagem'] ?? 'falha');
        $this->assertSame('lattice', $meta['estrategia']);
        $this->assertSame(
            ['Operacao', 'Situacao', 'Pagador/Recebedor', 'CPF/CNPJ', 'Data', 'Valor'],
            $meta['cabecalho']
        );
        $this->assertSame(7, $meta['linhas_dados']);
        $this->assertSame([1, 2], $meta['resumo']['paginas']);

        $recebido = collect($meta['tabelas'][0]['amostra'])->first(
            fn ($row) => str_contains($row[0] ?? '', 'Recebido')
        );
        $this->assertNotNull($recebido);
        $this->assertSame('de CARLOS ALMEIDA', $recebido[2]);
        @unlink($resultado['csv']);
    }

    public function test_rejeita_pdf_sem_texto(): void
    {
        $resultado = $this->extrair(base_path('tests/fixtures/pdf/pdf_sem_texto.pdf'));
        $this->assertFalse($resultado['meta']['sucesso'] ?? true);
        $this->assertStringContainsString('texto selecionável', $resultado['meta']['mensagem'] ?? '');
        @unlink($resultado['csv']);
    }

    public function test_service_extrai_tabela_sem_bordas(): void
    {
        $service = new ExtratorTabelaPdfService();
        $resultado = $service->extrair(base_path('tests/fixtures/pdf/relatorio_tabela_sem_bordas.pdf'));

        $this->assertTrue($resultado['sucesso']);
        $this->assertSame(5, $resultado['linhas_dados']);
        $this->assertFileExists($resultado['arquivo_csv']);
        @unlink($resultado['arquivo_csv']);
    }
}
