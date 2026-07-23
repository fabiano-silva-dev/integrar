<?php

namespace Tests\Feature;

use App\Models\DocumentoFiscal;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser;
use App\Services\AutomacaoFiscal\ImportadorExtratoNfeService;
use Database\Seeders\PortaisIntegracaoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantAutomacaoFiscalExtratoNfeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_parser_gera_resumo_sem_cfop_e_totais_de_colunas(): void
    {
        $parser = new ExtratoNfeEcacRsParser();
        $parsed = $parser->parseArquivo(base_path('tests/fixtures/extrato-nfe-ecac-rs-amostra.txt'));

        $this->assertCount(3, $parsed['documentos']);
        $this->assertFalse($parsed['resumo']['cfop_disponivel']);
        $this->assertSame(400.5, $parsed['resumo']['totais_colunas']['valor_total']);
        $this->assertSame(24.06, $parsed['resumo']['totais_colunas']['valor_icms']);
        $this->assertNotEmpty($parsed['avisos']);
        $this->assertSame(2, $parsed['resumo']['indicadores']['com_icms']);
        $this->assertSame(1, $parsed['resumo']['indicadores']['sem_base_icms']);
    }

    public function test_importacao_persiste_documentos_idempotente_por_chave(): void
    {
        Storage::fake('local');
        $this->seed(PortaisIntegracaoSeeder::class);

        $operadora = EmpresasOperadora::factory()->create();
        $empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'cnpj' => '11.222.333/0001-81',
        ]);

        $service = app(ImportadorExtratoNfeService::class);
        $path = base_path('tests/fixtures/extrato-nfe-ecac-rs-amostra.txt');

        $primeiro = $service->importarArquivo($empresa, $path, 'ExtratoNFe.txt');
        $this->assertSame(3, $primeiro['coleta']->quantidade_novos);

        $segundo = $service->importarArquivo($empresa, $path, 'ExtratoNFe.txt');
        $this->assertSame(0, $segundo['coleta']->quantidade_novos);
        $this->assertSame(3, $segundo['coleta']->quantidade_ignorados);

        $this->assertSame(3, DocumentoFiscal::query()->where('empresa_id', $empresa->id)->count());
    }
}
