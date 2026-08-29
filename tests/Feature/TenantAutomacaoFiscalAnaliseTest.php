<?php

namespace Tests\Feature;

use App\Models\DocumentoFiscal;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\PortalIntegracao;
use App\Models\User;
use App\Services\AutomacaoFiscal\AnaliseFiscalService;
use App\Services\AutomacaoFiscal\ImportadorExtratoNfeService;
use Database\Seeders\PortaisIntegracaoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantAutomacaoFiscalAnaliseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_normaliza_chave_acesso_removendo_nao_digitos(): void
    {
        $this->assertSame(
            '43260711222333000181550010000010011000000001',
            AnaliseFiscalService::normalizarChaveAcesso('4326 0711.2223.3300/0181-55 001 000001001 1000000001')
        );
        $this->assertNull(AnaliseFiscalService::normalizarChaveAcesso(''));
        $this->assertNull(AnaliseFiscalService::normalizarChaveAcesso(null));
    }

    public function test_entrada_e_saida_mesma_competencia_formam_uma_analise(): void
    {
        $this->seed(PortaisIntegracaoSeeder::class);

        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'role' => 'admin',
        ]);
        $empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'cnpj' => '11.222.333/0001-81',
        ]);
        $portal = PortalIntegracao::query()->where('codigo', 'ecac_rs')->firstOrFail();

        $this->actingAs($user);

        $base = [
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'portal_integracao_id' => $portal->id,
            'tipo_documento' => 'nfe',
            'competencia' => '2026-07',
            'data_emissao' => '2026-07-03',
            'valor_total' => 100,
            'origem' => 'ecac_rs_extrato_txt',
        ];

        DocumentoFiscal::create(array_merge($base, [
            'chave_acesso' => '43260711222333000181550010000010011000000001',
            'entrada_saida' => 'S',
            'numero' => '1001',
        ]));
        DocumentoFiscal::create(array_merge($base, [
            'chave_acesso' => '43260711222333000181550010000010021000000002',
            'entrada_saida' => 'E',
            'numero' => '1002',
            'valor_total' => 200,
        ]));
        // Outra competência não entra na mesma análise.
        DocumentoFiscal::create(array_merge($base, [
            'chave_acesso' => '43260811222333000181550010000010031000000003',
            'entrada_saida' => 'S',
            'numero' => '1003',
            'competencia' => '2026-08',
            'data_emissao' => '2026-08-01',
        ]));

        $service = app(AnaliseFiscalService::class);
        $lista = $service->listar((int) $empresa->id, (int) $portal->id);

        $this->assertCount(2, $lista->items());

        $julho = collect($lista->items())->firstWhere('competencia', '2026-07');
        $this->assertNotNull($julho);
        $this->assertSame(2, (int) $julho->quantidade_documentos);
        $this->assertEquals(300.0, (float) $julho->valor_total);
        $this->assertNull($julho->tipo_listagem);
        $this->assertSame('—', $julho->tipo_listagem_label);

        $detalhe = $service->carregar((int) $empresa->id, (int) $portal->id, '2026-07');
        $this->assertSame(2, $detalhe['resumo']['quantidade']);
        $this->assertFalse($detalhe['eh_nfse']);
        $this->assertSame('07/2026', $detalhe['competencia_label']);
    }

    public function test_nfse_emitidas_e_recebidas_formam_analises_separadas(): void
    {
        $this->seed(PortaisIntegracaoSeeder::class);

        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'role' => 'admin',
        ]);
        $empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'cnpj' => '39.706.780/0001-00',
        ]);
        $portal = PortalIntegracao::query()->where('codigo', 'nfse_nacional')->firstOrFail();

        $this->actingAs($user);

        $base = [
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'portal_integracao_id' => $portal->id,
            'tipo_documento' => 'nfse',
            'competencia' => '2026-08',
            'data_emissao' => '2026-08-10',
            'origem' => 'nfse_nacional_extrato_txt',
        ];

        DocumentoFiscal::create(array_merge($base, [
            'chave_acesso' => str_repeat('1', 50),
            'numero' => '1',
            'valor_total' => 100,
            'dados_complementares' => ['tipo_listagem' => 'emitidas'],
        ]));
        DocumentoFiscal::create(array_merge($base, [
            'chave_acesso' => str_repeat('2', 50),
            'numero' => '2',
            'valor_total' => 250,
            'dados_complementares' => ['tipo_listagem' => 'recebidas'],
        ]));
        DocumentoFiscal::create(array_merge($base, [
            'chave_acesso' => str_repeat('3', 50),
            'numero' => '3',
            'valor_total' => 50,
            'dados_complementares' => ['tipo_listagem' => 'recebidas'],
        ]));

        $service = app(AnaliseFiscalService::class);
        $lista = $service->listar((int) $empresa->id, (int) $portal->id);

        $this->assertCount(2, $lista->items());

        $emitidas = collect($lista->items())->firstWhere('tipo_listagem', 'emitidas');
        $recebidas = collect($lista->items())->firstWhere('tipo_listagem', 'recebidas');

        $this->assertNotNull($emitidas);
        $this->assertNotNull($recebidas);
        $this->assertSame(1, (int) $emitidas->quantidade_documentos);
        $this->assertEquals(100.0, (float) $emitidas->valor_total);
        $this->assertSame('Emitidas', $emitidas->tipo_listagem_label);
        $this->assertSame(2, (int) $recebidas->quantidade_documentos);
        $this->assertEquals(300.0, (float) $recebidas->valor_total);
        $this->assertSame('Recebidas', $recebidas->tipo_listagem_label);

        $detalheRecebidas = $service->carregar(
            (int) $empresa->id,
            (int) $portal->id,
            '2026-08',
            'recebidas'
        );
        $this->assertTrue($detalheRecebidas['eh_nfse']);
        $this->assertSame('recebidas', $detalheRecebidas['tipo_listagem']);
        $this->assertSame(2, $detalheRecebidas['resumo']['quantidade']);
        $this->assertEquals(300.0, (float) $detalheRecebidas['resumo']['valor_total']);

        $somenteRecebidas = $service->listar(
            (int) $empresa->id,
            (int) $portal->id,
            null,
            'recebidas'
        );
        $this->assertCount(1, $somenteRecebidas->items());
        $this->assertSame('recebidas', $somenteRecebidas->items()[0]->tipo_listagem);
        $this->assertSame(2, (int) $somenteRecebidas->items()[0]->quantidade_documentos);

        $competenciaAgosto = $service->listar(
            (int) $empresa->id,
            (int) $portal->id,
            '2026-08',
            'emitidas'
        );
        $this->assertCount(1, $competenciaAgosto->items());
        $this->assertSame('emitidas', $competenciaAgosto->items()[0]->tipo_listagem);
    }

    public function test_resumo_nfse_sem_listagem_usa_mesmo_filtro_da_tabela(): void
    {
        $this->seed(PortaisIntegracaoSeeder::class);

        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'role' => 'admin',
        ]);
        $empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'cnpj' => '39.706.780/0001-00',
        ]);
        $portal = PortalIntegracao::query()->where('codigo', 'nfse_nacional')->firstOrFail();

        $this->actingAs($user);

        $base = [
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'portal_integracao_id' => $portal->id,
            'tipo_documento' => 'nfse',
            'competencia' => '2026-08',
            'data_emissao' => '2026-08-10',
            'origem' => 'nfse_nacional_extrato_txt',
        ];

        foreach ([1, 2] as $n) {
            DocumentoFiscal::create(array_merge($base, [
                'chave_acesso' => str_repeat((string) $n, 50),
                'numero' => (string) $n,
                'valor_total' => 100 * $n,
                'dados_complementares' => ['tipo_listagem' => 'emitidas'],
            ]));
        }

        foreach ([3, 4, 5] as $n) {
            DocumentoFiscal::create(array_merge($base, [
                'chave_acesso' => str_repeat((string) $n, 50),
                'numero' => (string) $n,
                'valor_total' => 50 * $n,
                'dados_complementares' => ['tipo_listagem' => 'recebidas'],
            ]));
        }

        $service = app(AnaliseFiscalService::class);

        $semListagem = $service->carregar((int) $empresa->id, (int) $portal->id, '2026-08');
        $this->assertSame('emitidas', $semListagem['tipo_listagem']);
        $this->assertSame(2, $semListagem['resumo']['quantidade']);
        $this->assertSame(2, $semListagem['documentos_query']->count());

        $emitidas = $service->carregar((int) $empresa->id, (int) $portal->id, '2026-08', 'emitidas');
        $this->assertSame(2, $emitidas['resumo']['quantidade']);
        $this->assertSame(2, $emitidas['documentos_query']->count());

        $recebidas = $service->carregar((int) $empresa->id, (int) $portal->id, '2026-08', 'recebidas');
        $this->assertSame('recebidas', $recebidas['tipo_listagem']);
        $this->assertSame(3, $recebidas['resumo']['quantidade']);
        $this->assertSame(3, $recebidas['documentos_query']->count());
    }

    public function test_reimportacao_mesma_chave_nao_duplica_documento(): void
    {
        Storage::fake('local');
        $this->seed(PortaisIntegracaoSeeder::class);

        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'role' => 'admin',
        ]);
        $empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'cnpj' => '11.222.333/0001-81',
        ]);
        $portal = PortalIntegracao::query()->where('codigo', 'ecac_rs')->firstOrFail();

        $this->actingAs($user);

        $service = app(ImportadorExtratoNfeService::class);
        $path = base_path('tests/fixtures/extrato-nfe-ecac-rs-amostra.txt');

        $service->importarArquivo($empresa, $path, 'ExtratoNFe-a.txt');
        $service->importarArquivo($empresa, $path, 'ExtratoNFe-b.txt');

        $this->assertSame(3, DocumentoFiscal::query()->where('empresa_id', $empresa->id)->count());

        $lista = app(AnaliseFiscalService::class)->listar((int) $empresa->id, (int) $portal->id);
        $this->assertCount(1, $lista->items());
        $this->assertSame('2026-07', $lista->items()[0]->competencia);
        $this->assertSame(3, (int) $lista->items()[0]->quantidade_documentos);
    }
}
