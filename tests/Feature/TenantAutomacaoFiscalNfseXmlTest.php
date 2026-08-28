<?php

namespace Tests\Feature;

use App\Jobs\AutomacaoFiscal\BaixarDocumentoFiscalXmlJob;
use App\Jobs\AutomacaoFiscal\BaixarNfseXmlJob;
use App\Livewire\AutomacaoFiscal\ResumoFiscalDocumentos;
use App\Models\CertificadoDigital;
use App\Models\DocumentoFiscal;
use App\Models\Empresa;
use App\Models\EmpresaIntegracao;
use App\Models\EmpresasOperadora;
use App\Models\PortalIntegracao;
use App\Models\User;
use App\Services\AutomacaoFiscal\ConsultaAvulsa\ConsultaAvulsaCatalogo;
use App\Services\AutomacaoFiscal\ImportadorExtratoNfseService;
use App\Services\AutomacaoFiscal\NfseXmlDownloadService;
use App\Services\OperadoraStorage;
use Database\Seeders\PortaisIntegracaoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TenantAutomacaoFiscalNfseXmlTest extends TestCase
{
    use DatabaseTransactions;

    private EmpresasOperadora $operadora;
    private User $user;
    private Empresa $empresa;
    private PortalIntegracao $portal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PortaisIntegracaoSeeder::class);
        Storage::fake('local');

        $this->operadora = EmpresasOperadora::factory()->create();
        $this->user = User::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'role' => 'admin',
        ]);
        $this->empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'cnpj' => '16.679.526/0001-80',
            'nome' => 'Prestador NFS-e',
        ]);
        $this->portal = PortalIntegracao::query()->where('codigo', 'nfse_nacional')->firstOrFail();
        $this->actingAs($this->user);
    }

    public function test_importacao_enfileira_download_so_de_nfse_sem_xml(): void
    {
        config(['automacao_fiscal.fake_mode' => false]);
        Queue::fake();

        $this->criarIntegracaoComCertificado();

        $chaveNova = '43080031216679526000180000000000001726066074736113';
        $chaveJaBaixada = '43080031295070777000139000000000008626066548202908';

        DocumentoFiscal::create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'portal_integracao_id' => $this->portal->id,
            'tipo_documento' => 'nfse',
            'chave_acesso' => $chaveJaBaixada,
            'identificador_externo' => $chaveJaBaixada,
            'numero' => '18',
            'competencia' => '2026-06',
            'valor_total' => 10,
            'entrada_saida' => 'S',
            'origem' => 'nfse_nacional_extrato_txt',
            'xml_storage_path' => OperadoraStorage::put(
                'automacao-fiscal/nfse/'.$this->empresa->id,
                $chaveJaBaixada.'.xml',
                '<NFSe/>',
                $this->operadora->id
            ),
            'xml_baixado_em' => now(),
            'xml_fonte' => 'ws-sefin-nacional',
        ]);

        $path = $this->gravarExtrato([
            ['25/06/2026', '06/2026', '95591764000105', 'UFSM', 'Faxinal do Soturno/RS', '1.558,70', 'P100_GERADA', 'NFS-e emitida', 'emitidas', '17', $chaveNova],
            ['26/06/2026', '06/2026', '95591764000105', 'UFSM', 'Faxinal do Soturno/RS', '10,00', 'P100_GERADA', 'NFS-e emitida', 'emitidas', '18', $chaveJaBaixada],
        ]);

        app(ImportadorExtratoNfseService::class)->importarArquivo(
            $this->empresa,
            $path,
            'extratonfse.txt'
        );

        Queue::assertPushed(BaixarNfseXmlJob::class, 1);
        Queue::assertPushed(
            BaixarNfseXmlJob::class,
            fn (BaixarNfseXmlJob $job) => DocumentoFiscal::withoutGlobalScope('operadora')
                ->whereKey($job->documentoId)
                ->value('chave_acesso') === $chaveNova
        );
    }

    public function test_importacao_nao_enfileira_em_fake_mode(): void
    {
        config(['automacao_fiscal.fake_mode' => true]);
        Queue::fake();
        $this->criarIntegracaoComCertificado();

        $chave = '43080031216679526000180000000000001726066074736113';
        $path = $this->gravarExtrato([
            ['25/06/2026', '06/2026', '95591764000105', 'UFSM', 'Faxinal do Soturno/RS', '1.558,70', 'P100_GERADA', 'NFS-e emitida', 'emitidas', '17', $chave],
        ]);

        app(ImportadorExtratoNfseService::class)->importarArquivo(
            $this->empresa,
            $path,
            'extratonfse.txt'
        );

        Queue::assertNothingPushed();
    }

    public function test_botao_avulso_rejeita_nfe_no_fluxo_nfse_e_vice_versa(): void
    {
        Queue::fake();

        $nfse = DocumentoFiscal::create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'portal_integracao_id' => $this->portal->id,
            'tipo_documento' => 'nfse',
            'chave_acesso' => '43080031216679526000180000000000001726066074736113',
            'competencia' => '2026-06',
            'valor_total' => 1,
            'origem' => 'nfse_nacional_extrato_txt',
        ]);

        $nfe = DocumentoFiscal::create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'tipo_documento' => 'nfe',
            'modelo' => '55',
            'chave_acesso' => '43260616679526000180550100000003431735351222',
            'competencia' => '2026-06',
            'valor_total' => 1,
            'origem' => 'ecac_rs_extrato_txt',
        ]);

        $this->assertTrue(app(NfseXmlDownloadService::class)->chaveNfseValida($nfse));
        $this->assertFalse(app(NfseXmlDownloadService::class)->chaveNfseValida($nfe));

        Livewire::test(ResumoFiscalDocumentos::class)
            ->call('baixarXml', $nfe->id);

        Queue::assertNotPushed(BaixarNfseXmlJob::class);
        Queue::assertPushed(BaixarDocumentoFiscalXmlJob::class);
    }

    public function test_catalogo_avulsa_tem_xml_nfse_por_chave(): void
    {
        $tipo = ConsultaAvulsaCatalogo::porCodigo('xml_nfse_por_chave');
        $this->assertNotNull($tipo);
        $this->assertTrue(ConsultaAvulsaCatalogo::rolePodeAcessar('xml_nfse_por_chave', 'super_admin'));
    }

    private function criarIntegracaoComCertificado(): CertificadoDigital
    {
        $path = OperadoraStorage::put(
            'automacao-fiscal/certificados',
            'cert-nfse.pfx',
            'conteudo-fake-pfx',
            $this->operadora->id
        );

        $certificado = CertificadoDigital::create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'nome' => 'A1 Empresa NFS-e',
            'tipo' => 'A1',
            'arquivo_path' => $path,
            'senha_criptografada' => 'senha-teste',
            'ativo' => true,
            'valido_ate' => now()->addYear(),
        ]);

        EmpresaIntegracao::create([
            'empresa_operadora_id' => $this->operadora->id,
            'empresa_id' => $this->empresa->id,
            'portal_integracao_id' => $this->portal->id,
            'ativo' => true,
            'modo_autenticacao' => 'certificado_a1',
            'certificado_digital_id' => $certificado->id,
            'status_configuracao' => 'configurado',
        ]);

        return $certificado;
    }

    /**
     * @param  list<list<string>>  $linhas
     */
    private function gravarExtrato(array $linhas): string
    {
        $header = 'dt_Geracao;Competencia;CNPJ_Contraparte;Nome_Contraparte;Municipio_Emissor;Valor_Servico;Sit;Sit_Label;Tipo;Numero;Chave_NFS-e';
        $body = implode("\n", array_map(fn (array $cols) => implode(';', $cols), $linhas));
        $path = sys_get_temp_dir().'/extratonfse-'.uniqid('', true).'.txt';
        file_put_contents($path, $header."\n".$body."\n");

        return $path;
    }
}
