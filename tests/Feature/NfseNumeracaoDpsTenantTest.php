<?php

namespace Tests\Feature;

use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Nfse\DpsLocal;
use App\Models\Nfse\DpsSequencia;
use App\Models\Nfse\Nota;
use App\Models\User;
use App\Services\Nfse\AcessoEmissaoNfse;
use App\Services\Nfse\ConferenciaNfse;
use App\Services\Nfse\Dps\GeradorDps;
use App\Services\Nfse\Dps\NumeracaoDpsHomologacao;
use App\Services\Nfse\Dps\PrepararDpsHomologacao;
use App\Services\Nfse\PreparacaoNfse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class NfseNumeracaoDpsTenantTest extends TestCase
{
    use DatabaseTransactions;

    private Empresa $empresa;
    private Nota $nota;
    private NumeracaoDpsHomologacao $service;
    private array $fiscal = [
        'municipio_prestacao' => '4308003', 'regApTribSN' => '1',
        'escopo_confirmado' => true, 'ibscbs_nao_exigido' => true,
        'percentuais_tributos' => ['pTotTribFed' => '5.00', 'pTotTribEst' => '0.00', 'pTotTribMun' => '2.00'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['automacao_fiscal.nfse_dps_local_enabled' => true, 'automacao_fiscal.nfse_dps_algoritmo' => 'rsa-sha256']);
        $op = EmpresasOperadora::factory()->create(['ativo' => true]);
        $this->empresa = Empresa::factory()->create(['empresa_operadora_id' => $op->id, 'cnpj' => '11222333000181', 'codigo_municipio_ibge' => '4308003', 'ativo' => true]);
        $this->actingAs(User::factory()->admin()->create(['empresa_operadora_id' => $op->id, 'ativo' => true]));
        $preparo = app(PreparacaoNfse::class);
        $preparo->salvarConfiguracao($this->empresa->id, ['habilitado_preparo' => true, 'regime_tributario' => 'simples_nacional', 'serie_dps' => '00001']);
        $t = $preparo->salvarTomador($this->empresa->id, null, ['documento' => '52998224725', 'nome' => 'Tomador teste', 'ativo' => true]);
        $s = $preparo->salvarServico($this->empresa->id, null, ['codigo' => 'S1', 'nome' => 'Serviço', 'descricao' => 'Serviço', 'codigo_tributacao_nacional' => '170101', 'ativo' => true]);
        $this->nota = $preparo->salvarRascunho($this->empresa->id, (string) Str::uuid(), ['tomador_id' => $t->id, 'servico_id' => $s->id, 'competencia' => '2026-11-01', 'descricao' => 'Serviço', 'valor' => '100.01'])->refresh();
        app(ConferenciaNfse::class)->prepararLote($this->empresa->id, '2026-11', [$this->nota->id => $this->nota->versao], '');
        $cert = CertificadoDigital::create([
            'empresa_operadora_id' => $op->id, 'empresa_id' => null, 'nome' => 'A1 teste', 'tipo' => 'A1',
            'arquivo_path' => 'teste-inexistente.pfx', 'senha_criptografada' => 'teste', 'documento_titular' => '52998224725',
            'fingerprint' => str_repeat('a', 64), 'ativo' => true, 'valido_de' => now()->subDay(), 'valido_ate' => now()->addMonth(),
        ]);
        app(AcessoEmissaoNfse::class)->salvar($this->empresa->id, ['modo' => 'certificado_representante', 'canal' => 'api', 'certificado_digital_id' => $cert->id]);
        $this->service = app(NumeracaoDpsHomologacao::class);
    }

    private function simularPreparador(bool $falhar = false, bool $divergente = false): void
    {
        // Isola apenas numeração e atomicidade; assinatura e XSD têm testes separados.
        $mock = $this->createMock(PrepararDpsHomologacao::class);
        $mock->expects($this->once())->method('preparar')->willReturnCallback(function ($empresa, $nota, $numero, $fiscal) use ($falhar, $divergente) {
            if ($falhar) {
                throw new RuntimeException('Falha simulada na assinatura.');
            }
            $id = GeradorDps::id('4308003', '11222333000181', '1', $numero);
            $amb = $divergente ? '1' : '2';
            return '<DPS xmlns="'.GeradorDps::NS.'"><infDPS Id="'.$id.'"><tpAmb>'.$amb.'</tpAmb><serie>1</serie><nDPS>'.$numero.'</nDPS><cLocEmi>4308003</cLocEmi><prest><CNPJ>11222333000181</CNPJ></prest></infDPS><Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignedInfo><Reference URI="#'.$id.'"/></SignedInfo></Signature></DPS>';
        });
        $this->app->instance(PrepararDpsHomologacao::class, $mock);
        $this->service = app(NumeracaoDpsHomologacao::class);
    }

    public function test_reserva_e_repeticao_preservam_numero_e_xml_criptografado(): void
    {
        $seq = $this->service->inicializar($this->empresa->id, '100');
        $this->simularPreparador();
        $a = $this->service->preparar($this->empresa->id, $this->nota->id, $this->fiscal);
        $b = $this->service->preparar($this->empresa->id, $this->nota->id, $this->fiscal);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(101, $seq->refresh()->ultimo_numero);
        $this->assertSame(101, $a->numero);
        $this->assertSame(2, $a->ambiente);
        $this->assertSame('preparada_local', $a->status);
        $this->assertSame($a->xml_criptografado, $b->xml_criptografado);
        $this->assertNotSame($a->xml_criptografado, $a->getRawOriginal('xml_criptografado'));
        $this->assertArrayNotHasKey('xml_criptografado', $a->toArray());
        $this->assertSame('em_lote', $this->nota->refresh()->status);
        $this->assertSame(1, DpsLocal::daEmpresa($this->empresa)->count());
    }

    public function test_falha_de_assinatura_reverte_reserva(): void
    {
        $seq = $this->service->inicializar($this->empresa->id, '10');
        $this->simularPreparador(true);
        try {
            $this->service->preparar($this->empresa->id, $this->nota->id, $this->fiscal);
            $this->fail('Era esperada uma falha.');
        } catch (RuntimeException $e) {
            $this->assertSame('Falha simulada na assinatura.', $e->getMessage());
            $this->assertSame(10, $seq->refresh()->ultimo_numero);
            $this->assertSame(0, DpsLocal::daEmpresa($this->empresa)->count());
        }
    }

    public function test_xml_de_outro_ambiente_nao_e_persistido(): void
    {
        $seq = $this->service->inicializar($this->empresa->id, '10');
        $this->simularPreparador(false, true);
        try {
            $this->service->preparar($this->empresa->id, $this->nota->id, $this->fiscal);
            $this->fail('Era esperada uma falha.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Identidade', $e->getMessage());
            $this->assertSame(10, $seq->refresh()->ultimo_numero);
            $this->assertSame(0, DpsLocal::daEmpresa($this->empresa)->count());
        }
    }

    public function test_nao_reinicia_sequencia(): void
    {
        $this->service->inicializar($this->empresa->id, '100');
        $this->expectException(ValidationException::class);
        $this->service->inicializar($this->empresa->id, '0');
    }

    public function test_alteracao_fiscal_nao_sobrescreve_xml(): void
    {
        $this->service->inicializar($this->empresa->id, '0');
        $this->simularPreparador();
        $this->service->preparar($this->empresa->id, $this->nota->id, $this->fiscal);
        $this->fiscal['percentuais_tributos']['pTotTribFed'] = '6.00';
        $this->expectException(ValidationException::class);
        $this->service->preparar($this->empresa->id, $this->nota->id, $this->fiscal);
    }

    public function test_outro_escritorio_nao_inicializa_sequencia(): void
    {
        $outro = EmpresasOperadora::factory()->create(['ativo' => true]);
        $this->actingAs(User::factory()->admin()->create(['empresa_operadora_id' => $outro->id, 'ativo' => true]));
        $this->expectException(ValidationException::class);
        $this->service->inicializar($this->empresa->id, '0');
    }
}
