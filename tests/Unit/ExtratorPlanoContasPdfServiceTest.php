<?php

namespace Tests\Unit;

use App\Services\Importacao\ExtratorPlanoContasPdfService;
use Tests\TestCase;

class ExtratorPlanoContasPdfServiceTest extends TestCase
{
    public function test_extrai_linhas_de_pdf_dominio_nativo(): void
    {
        $path = base_path('docs/plano-contas-dominio.pdf');
        $this->assertFileExists($path);

        $service = new ExtratorPlanoContasPdfService();
        $resultado = $service->extrairDominio($path);

        $this->assertNotEmpty($resultado['linhas']);
        $this->assertSame('codigo', $resultado['colunas'][0]);
        $this->assertArrayHasKey('classificacao', $resultado['linhas'][0]);

        $conta742 = collect($resultado['linhas'])->firstWhere('codigo', '742');
        $this->assertNotNull($conta742);
        $this->assertSame('1.1.2.01.001', $conta742['classificacao']);
        $this->assertSame('ARBAZA ALIMENTOS LTDA - SORRISO MT', $conta742['descricao']);
    }
}
