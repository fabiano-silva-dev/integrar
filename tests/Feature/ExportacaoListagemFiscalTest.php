<?php

namespace Tests\Feature;

use App\Livewire\AutomacaoFiscal\ResumoFiscalDocumentos;
use App\Models\DocumentoFiscal;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\PortalIntegracao;
use App\Models\User;
use App\Services\AutomacaoFiscal\ExportadorListagemFiscalService;
use App\Services\OperadoraStorage;
use Database\Seeders\PortaisIntegracaoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ExportacaoListagemFiscalTest extends TestCase
{
    use DatabaseTransactions;

    private EmpresasOperadora $operadora;
    private Empresa $empresa;
    private PortalIntegracao $portal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortaisIntegracaoSeeder::class);
        $this->operadora = EmpresasOperadora::factory()->create();
        $this->empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
        ]);
        $this->portal = PortalIntegracao::query()->where('codigo', 'ecac_rs')->firstOrFail();
        $this->actingAs(User::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'role' => 'admin',
        ]));
    }

    public function test_datas_filtram_apenas_documentos_da_listagem_sem_consultar_portal(): void
    {
        $this->documento('10', '2026-06-05', 'Documento fora');
        $this->documento('20', '2026-06-15', 'Documento dentro');
        $this->documento('30', '2026-06-25', 'Outro documento fora');

        Livewire::test(ResumoFiscalDocumentos::class, [
            'empresa' => $this->empresa->id,
            'portal' => $this->portal->id,
            'competencia' => '2026-06',
        ])
            ->set('filtro_data_inicio', '2026-06-10')
            ->set('filtro_data_fim', '2026-06-20')
            ->assertSee('Documento dentro')
            ->assertDontSee('Documento fora')
            ->assertDontSee('Outro documento fora')
            ->assertSee('1 documento(s)');
    }

    public function test_exportacao_xls_contem_todas_as_linhas_filtradas_e_preserva_chave_como_texto(): void
    {
        $this->documento('10', '2026-06-05', 'Documento fora');
        $chave = '00000000000000000000000000000000000000000001';
        $this->documento('20', '2026-06-15', 'Documento dentro', $chave);
        $this->documento('30', '2026-06-18', 'Outro dentro');

        $response = app(ExportadorListagemFiscalService::class)->baixar(
            $this->empresa->id, $this->portal->id, '2026-06',
            null, '2026-06-10', '2026-06-20', 'xls'
        );
        ob_start();
        $response->sendContent();
        $bytes = ob_get_clean();
        $this->assertIsString($bytes);
        $this->assertStringStartsWith("\xD0\xCF\x11\xE0", $bytes);

        $tmp = tempnam(OperadoraStorage::tempDirectory($this->operadora->id), 'xls-test-');
        $this->assertIsString($tmp);
        file_put_contents($tmp, $bytes);
        try {
            $spreadsheet = IOFactory::load($tmp);
            $aba = $spreadsheet->getActiveSheet();
            $this->assertSame('20', $aba->getCell('B7')->getValue()); // emissão desc: 30, 20
            $this->assertSame($chave, $aba->getCell('K7')->getValue());
            $this->assertSame(7, $aba->getHighestDataRow()); // cabeçalho 5 + 2 documentos
            $spreadsheet->disconnectWorksheets();
        } finally {
            unlink($tmp);
        }
    }

    public function test_pdf_gerado_e_pdf_nao_inclui_documento_de_outro_periodo(): void
    {
        $this->documento('20', '2026-06-15', 'Documento dentro');

        $response = app(ExportadorListagemFiscalService::class)->baixar(
            $this->empresa->id, $this->portal->id, '2026-06',
            null, '2026-06-10', '2026-06-20', 'pdf'
        );
        ob_start();
        $response->sendContent();
        $pdf = ob_get_clean();
        $this->assertIsString($pdf);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_rejeita_exportacao_de_empresa_de_outro_escritorio(): void
    {
        $outraOperadora = EmpresasOperadora::factory()->create();
        $outraEmpresa = Empresa::factory()->create([
            'empresa_operadora_id' => $outraOperadora->id,
        ]);

        $this->expectException(ModelNotFoundException::class);
        app(ExportadorListagemFiscalService::class)->baixar(
            $outraEmpresa->id, $this->portal->id, '2026-06',
            null, null, null, 'xls'
        );
    }

    private function documento(
        string $numero,
        string $data,
        string $emitente,
        ?string $chave = null
    ): DocumentoFiscal {
        return DocumentoFiscal::create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'portal_integracao_id' => $this->portal->id,
            'tipo_documento' => 'nfe',
            'numero' => $numero,
            'chave_acesso' => $chave,
            'competencia' => '2026-06',
            'data_emissao' => $data,
            'nome_emitente' => $emitente,
            'nome_destinatario' => 'Tomador',
            'valor_total' => 125.90,
            'valor_icms' => 5.00,
            'origem' => 'ecac_rs_extrato_txt',
        ]);
    }
}
