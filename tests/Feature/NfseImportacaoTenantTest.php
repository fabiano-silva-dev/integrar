<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Nfse\Nota;
use App\Models\User;
use App\Services\Nfse\ImportacaoPlanilha;
use App\Services\Nfse\PreparacaoNfse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NfseImportacaoTenantTest extends TestCase
{
    use DatabaseTransactions;

    private Empresa $empresa;
    private Empresa $outra;
    private ImportacaoPlanilha $service;
    private array $config = [
        'cabecalho' => 1, 'aba' => 0, 'delimitador' => 'ponto_virgula', 'identificacao' => 'documento',
        'formato_valor' => 'br', 'coluna_tomador' => 0, 'coluna_valor' => 1, 'coluna_servico' => 2, 'coluna_descricao' => null, 'servico_fixo' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $op = EmpresasOperadora::factory()->create(['ativo' => true]);
        $this->empresa = Empresa::factory()->create(['empresa_operadora_id' => $op->id, 'ativo' => true]);
        $this->outra = Empresa::factory()->create(['empresa_operadora_id' => $op->id, 'ativo' => true]);
        $this->actingAs(User::factory()->create(['empresa_operadora_id' => $op->id, 'role' => 'admin', 'ativo' => true]));
        $preparo = app(PreparacaoNfse::class);
        foreach ([$this->empresa, $this->outra] as $empresa) {
            $preparo->salvarConfiguracao($empresa->id, ['habilitado_preparo' => true]);
            $preparo->salvarTomador($empresa->id, null, ['documento' => '52998224725', 'nome' => 'Tomador fictício', 'ativo' => true]);
            $preparo->salvarServico($empresa->id, null, ['codigo' => 'S1', 'nome' => 'Serviço fictício', 'descricao' => 'Mensalidade', 'codigo_tributacao_nacional' => '170101', 'ativo' => true]);
        }
        $this->service = app(ImportacaoPlanilha::class);
    }

    private function arquivo(string $conteudo = "CPF;Valor;Serviço\n52998224725;123,45;S1\n00000000000;50,00;S1\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cliente.csv', $conteudo);
    }

    private function importarPrimeiro(): \App\Models\Nfse\Importacao
    {
        $arquivo = $this->arquivo();
        $previa = $this->service->previa($this->empresa->id, $arquivo, '2026-11-01', null, $this->config);
        $this->assertSame(1, $previa['aptas']);
        $this->assertSame(1, $previa['pendentes']);
        $this->assertSame('123.45', $previa['total']);
        return $this->service->importar($this->empresa->id, $arquivo, '2026-11-01', null, $this->config, 'Cliente', $previa['conferencia']);
    }

    public function test_layout_reutilizado_e_pendencias_preservadas_sem_transmissao(): void
    {
        $registro = $this->importarPrimeiro();
        Storage::disk('local')->assertExists($registro->storage_path);
        $nota = Nota::daEmpresa($this->empresa)->where('importacao_id', $registro->id)->sole();
        $this->assertSame('rascunho', $nota->status);
        $this->assertSame(2, (int) $nota->linha_importacao);
        $this->assertNotNull($registro->relatorio[1]['erro']);
        $previa = $this->service->previa($this->empresa->id, $this->arquivo(), '2026-12-01', $registro->layout_id, []);
        $this->assertSame(1, $previa['aptas']);
    }

    public function test_mesmo_arquivo_na_mesma_competencia_nao_reimporta(): void
    {
        $registro = $this->importarPrimeiro();
        $this->expectException(ValidationException::class);
        $this->service->previa($this->empresa->id, $this->arquivo(), '2026-11-15', $registro->layout_id, []);
    }

    public function test_layout_de_outro_prestador_nao_pode_ser_usado(): void
    {
        $registro = $this->importarPrimeiro();
        $this->expectException(ModelNotFoundException::class);
        $this->service->previa($this->outra->id, $this->arquivo(), '2026-12-01', $registro->layout_id, []);
    }

    public function test_cabecalho_modificado_exige_novo_layout(): void
    {
        $registro = $this->importarPrimeiro();
        $this->expectException(ValidationException::class);
        $this->service->previa($this->empresa->id, $this->arquivo("Valor;CPF;Serviço\n123,45;52998224725;S1\n"), '2026-12-01', $registro->layout_id, []);
    }

    public function test_alteracao_da_planilha_apos_conferencia_bloqueia_importacao(): void
    {
        $previa = $this->service->previa($this->empresa->id, $this->arquivo(), '2026-11-01', null, $this->config);
        $this->expectException(ValidationException::class);
        $this->service->importar($this->empresa->id, $this->arquivo("CPF;Valor;Serviço\n52998224725;150,00;S1\n"), '2026-11-01', null, $this->config, 'Cliente', $previa['conferencia']);
    }
}
