<?php

namespace Tests\Feature;

use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Nfse\Nota;
use App\Models\Nfse\TransmissaoRestrita;
use App\Models\User;
use App\Services\Nfse\AcessoEmissaoNfse;
use App\Services\Nfse\ConferenciaNfse;
use App\Services\Nfse\Dps\AssinadorDps;
use App\Services\Nfse\Dps\EmissaoRestrita;
use App\Services\Nfse\Dps\GeradorDps;
use App\Services\Nfse\Dps\NumeracaoDpsHomologacao;
use App\Services\Nfse\Dps\PrepararDpsHomologacao;
use App\Services\Nfse\Dps\TransporteSefinRestrita;
use App\Services\Nfse\Dps\ValidadorDps;
use App\Services\Nfse\PreparacaoNfse;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class NfseEmissaoRestritaTenantTest extends TestCase
{
    use DatabaseTransactions;

    private Empresa $empresa;
    private Nota $nota;
    private \App\Models\Nfse\DpsLocal $dps;
    private const DOCUMENTO = '11222333000181';
    private const CHAVE = '43080031211222333000181000000000001726066074736113';

    protected function setUp(): void
    {
        parent::setUp();
        config(['automacao_fiscal.nfse_dps_local_enabled' => true, 'automacao_fiscal.nfse_dps_restrita_enabled' => true,
            'automacao_fiscal.nfse_dps_restrita_prestador' => self::DOCUMENTO, 'automacao_fiscal.nfse_dps_algoritmo' => 'rsa-sha256']);
        $op = EmpresasOperadora::factory()->create(['ativo' => true]);
        $this->empresa = Empresa::factory()->create(['empresa_operadora_id' => $op->id, 'cnpj' => self::DOCUMENTO, 'codigo_municipio_ibge' => '4308003', 'ativo' => true]);
        $this->actingAs(User::factory()->admin()->create(['empresa_operadora_id' => $op->id, 'ativo' => true]));
        $p = app(PreparacaoNfse::class);
        $p->salvarConfiguracao($this->empresa->id, ['habilitado_preparo' => true, 'regime_tributario' => 'simples_nacional', 'serie_dps' => '1']);
        $t = $p->salvarTomador($this->empresa->id, null, ['documento' => '52998224725', 'nome' => 'Tomador teste', 'ativo' => true]);
        $s = $p->salvarServico($this->empresa->id, null, ['codigo' => 'S1', 'nome' => 'Serviço', 'descricao' => 'Serviço', 'codigo_tributacao_nacional' => '170101', 'ativo' => true]);
        $this->nota = $p->salvarRascunho($this->empresa->id, (string) Str::uuid(), ['tomador_id' => $t->id, 'servico_id' => $s->id,
            'competencia' => '2026-11-01', 'descricao' => 'Serviço', 'valor' => '100.01'])->refresh();
        app(ConferenciaNfse::class)->prepararLote($this->empresa->id, '2026-11', [$this->nota->id => $this->nota->versao], '');
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'Teste local'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_pkey_export($key, $privado);
        openssl_x509_export($cert, $publico);
        $c = CertificadoDigital::create(['empresa_operadora_id' => $op->id, 'empresa_id' => null, 'nome' => 'A1 teste', 'tipo' => 'A1',
            'arquivo_path' => 'teste-inexistente.pfx', 'senha_criptografada' => 'teste', 'documento_titular' => '52998224725',
            'fingerprint' => openssl_x509_fingerprint($cert, 'sha256'), 'ativo' => true, 'valido_de' => now()->subDay(), 'valido_ate' => now()->addDay()]);
        app(AcessoEmissaoNfse::class)->salvar($this->empresa->id, ['modo' => 'certificado_representante', 'canal' => 'api', 'certificado_digital_id' => $c->id]);
        $fiscal = ['municipio_prestacao' => '4308003', 'regApTribSN' => '1', 'escopo_confirmado' => true, 'ibscbs_nao_exigido' => true,
            'percentuais_tributos' => ['pTotTribFed' => '5.00', 'pTotTribEst' => '0.00', 'pTotTribMun' => '2.00']];
        $mock = $this->createMock(PrepararDpsHomologacao::class);
        $mock->method('preparar')->willReturnCallback(fn ($empresa, $nota, $numero) => (new AssinadorDps)->assinar((new GeradorDps)->gerar($fiscal + [
            'prestador' => self::DOCUMENTO, 'tomador' => '52998224725', 'nome_tomador' => 'Tomador teste',
            'municipio_emissor' => '4308003', 'serie' => '1', 'numero' => $numero, 'competencia' => '2026-11-01',
            'emissao' => new DateTimeImmutable('2026-11-27T10:00:00-03:00'), 'opSimpNac' => '3', 'valor' => '100.01',
            'descricao' => 'Serviço', 'codigo_tributacao_nacional' => '170101']), $publico, $privado, 'rsa-sha256'));
        $this->app->instance(PrepararDpsHomologacao::class, $mock);
        $num = app(NumeracaoDpsHomologacao::class);
        $num->inicializar($this->empresa->id, '0');
        $this->dps = $num->preparar($this->empresa->id, $this->nota->id, $fiscal);
        // XSD oficial ainda pendente: testa coordenação, não homologação fiscal.
        $this->app->instance(ValidadorDps::class, $this->createMock(ValidadorDps::class));
    }

    private function aprovar(EmissaoRestrita $service): TransmissaoRestrita
    {
        return $service->aprovar($this->empresa->id, $this->dps->id, $this->dps->xml_hash);
    }

    public function test_timeout_impede_novo_post_e_consulta_404_nao_libera_reenvio(): void
    {
        $mock = $this->createMock(TransporteSefinRestrita::class);
        $mock->expects($this->once())->method('enviar')->willThrowException(new RuntimeException('timeout simulado'));
        $mock->expects($this->once())->method('consultarDps')->willReturn(['http' => 404, 'body' => '{}']);
        $mock->expects($this->never())->method('consultarNfse');
        $this->app->instance(TransporteSefinRestrita::class, $mock);
        $service = app(EmissaoRestrita::class);
        $r = $this->aprovar($service);
        $this->assertSame($r->id, $this->aprovar($service)->id);
        $this->assertSame('incerta', $service->enviar($this->empresa->id, $r->id)->status);
        $this->travel(91)->seconds();
        $this->assertSame('incerta', $service->reconciliar($this->empresa->id, $r->id)->status);
        $this->assertSame('em_lote', $this->nota->refresh()->status);
        $this->expectException(ValidationException::class);
        $service->enviar($this->empresa->id, $r->id);
    }

    public function test_reconciliacao_recupera_xml_sem_reenviar(): void
    {
        $xml = new \DOMDocument;
        $xml->loadXML($this->dps->xml_criptografado, LIBXML_NONET);
        $nfse = '<NFSe xmlns="'.GeradorDps::NS.'"><infNFSe Id="NFS'.self::CHAVE.'"><cStat>100</cStat><emit><CNPJ>'.self::DOCUMENTO.'</CNPJ></emit>'.$xml->saveXML($xml->documentElement).'</infNFSe></NFSe>';
        $mock = $this->createMock(TransporteSefinRestrita::class);
        $mock->expects($this->once())->method('enviar')->willReturn(['http' => 500, 'body' => '{}']);
        $mock->expects($this->once())->method('consultarDps')->willReturn(['http' => 200, 'body' => json_encode(['idDps' => $this->dps->id_dps, 'chaveAcesso' => self::CHAVE])]);
        $mock->expects($this->once())->method('consultarNfse')->willReturn(['http' => 200, 'body' => json_encode(['chaveAcesso' => self::CHAVE, 'nfseXmlGZipB64' => base64_encode(gzencode($nfse))])]);
        $this->app->instance(TransporteSefinRestrita::class, $mock);
        $service = app(EmissaoRestrita::class);
        $r = $this->aprovar($service);
        $service->enviar($this->empresa->id, $r->id);
        $this->travel(91)->seconds();
        $r = $service->reconciliar($this->empresa->id, $r->id);
        $this->assertSame('confirmada_teste', $r->status);
        $this->assertSame(self::CHAVE, $r->chave_acesso);
        $this->assertSame($nfse, $r->nfse_xml_criptografado);
        $this->assertArrayNotHasKey('nfse_xml_criptografado', $r->toArray());
        $this->assertSame('em_lote', $this->nota->refresh()->status);
    }

    public function test_tela_e_download_dps_sao_restritos_ao_escritorio_e_perfil(): void
    {
        session(['empresa_selecionada_id' => $this->empresa->id]);
        config(['automacao_fiscal.nfse_dps_restrita_enabled' => false]);
        \Livewire\Livewire::test(\App\Livewire\Nfse\Homologacao::class)
            ->assertSee('SEM VALOR FISCAL')->set('mes', '2026-11')
            ->call('abrirDps', $this->dps->id)
            ->assertSee($this->dps->id_dps)->assertDontSee('Transmitir uma vez à SEFIN de testes');
        $url = route('nfse.homologacao.dps', ['empresa' => $this->empresa->id, 'dps' => $this->dps->id]);
        $this->get($url)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="HOMOLOGACAO-'.$this->dps->id_dps.'.xml"')
            ->assertContent($this->dps->xml_criptografado);
        $this->actingAs(User::factory()->create(['empresa_operadora_id' => $this->empresa->empresa_operadora_id, 'role' => 'operador', 'ativo' => true]));
        $this->get($url)->assertForbidden();
        $this->get(route('nfse.homologacao'))->assertForbidden();
    }

    public function test_download_rejeita_xml_adulterado(): void
    {
        $this->dps->xml_criptografado = '<DPS/>';
        $this->dps->save();
        $this->get(route('nfse.homologacao.dps', ['empresa' => $this->empresa->id, 'dps' => $this->dps->id]))->assertStatus(422);
    }

    public function test_homologacao_nao_libera_acao_externa_com_flag_desligada(): void
    {
        session(['empresa_selecionada_id' => $this->empresa->id]);
        config(['automacao_fiscal.nfse_dps_restrita_enabled' => false]);
        $mock = $this->createMock(TransporteSefinRestrita::class);
        $mock->expects($this->never())->method('enviar');
        $this->app->instance(TransporteSefinRestrita::class, $mock);
        \Livewire\Livewire::test(\App\Livewire\Nfse\Homologacao::class)
            ->call('abrirDps', $this->dps->id)
            ->set('revisado', true)->call('aprovar')->assertForbidden();
    }


    private function criarFila(): \App\Models\Nfse\FilaHomologacao
    {
        config(['automacao_fiscal.nfse_dps_fila_enabled' => true]);
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\Nfse\TransmitirDpsRestritaJob::class]);

        return app(\App\Services\Nfse\Dps\FilaEmissaoRestrita::class)->aprovar($this->empresa->id, '2026-11', [
            ['id' => $this->dps->id, 'hash' => $this->dps->xml_hash],
        ]);
    }

    public function test_fila_reserva_e_impede_envio_individual_e_reentrega(): void
    {
        $fila = $this->criarFila();
        $r = TransmissaoRestrita::daEmpresa($this->empresa)->where('fila_homologacao_id', $fila->id)->firstOrFail();
        $mock = $this->createMock(TransporteSefinRestrita::class);
        $mock->expects($this->once())->method('enviar')->willThrowException(new RuntimeException('timeout simulado'));
        $this->app->instance(TransporteSefinRestrita::class, $mock);
        try {
            app(EmissaoRestrita::class)->enviar($this->empresa->id, $r->id);
            $this->fail('O envio individual deveria ser bloqueado.');
        } catch (ValidationException $e) {
            $this->assertSame('pendente', $r->refresh()->fila_status);
        }
        $job = new \App\Jobs\Nfse\TransmitirDpsRestritaJob((int) $fila->empresa_operadora_id, $this->empresa->id, $fila->id, $r->id);
        $autor = auth()->user();
        auth()->guard()->forgetUser();
        $job->handle(app(\App\Services\Nfse\Dps\FilaEmissaoRestrita::class));
        $job->handle(app(\App\Services\Nfse\Dps\FilaEmissaoRestrita::class));
        $this->assertNull(auth()->guard()->user());
        auth()->guard()->setUser($autor);
        $this->assertSame('concluido', $r->refresh()->fila_status);
        $this->assertSame('incerta', $r->status);
        $this->assertNotNull($r->envio_iniciado_em);
        $this->assertSame('em_lote', $this->nota->refresh()->status);
    }

    public function test_fila_bloqueia_autorizador_inativado_e_isola_tenant(): void
    {
        $fila = $this->criarFila();
        $r = TransmissaoRestrita::daEmpresa($this->empresa)->where('fila_homologacao_id', $fila->id)->firstOrFail();
        $mock = $this->createMock(TransporteSefinRestrita::class);
        $mock->expects($this->never())->method('enviar');
        $this->app->instance(TransporteSefinRestrita::class, $mock);
        $svc = app(\App\Services\Nfse\Dps\FilaEmissaoRestrita::class);
        $outro = EmpresasOperadora::factory()->create(['ativo' => true]);
        $svc->processar($outro->id, $this->empresa->id, $fila->id, $r->id);
        $this->assertSame('pendente', $r->refresh()->fila_status);
        User::query()->whereKey($fila->usuario_id)->update(['ativo' => false]);
        $svc->processar((int) $fila->empresa_operadora_id, $this->empresa->id, $fila->id, $r->id);
        $this->assertSame('bloqueado', $r->refresh()->fila_status);
        $this->assertNull($r->envio_iniciado_em);
    }

    public function test_aprovacao_coletiva_reverte_se_um_item_for_invalido(): void
    {
        config(['automacao_fiscal.nfse_dps_fila_enabled' => true]);
        \Illuminate\Support\Facades\Bus::fake();
        try {
            app(\App\Services\Nfse\Dps\FilaEmissaoRestrita::class)->aprovar($this->empresa->id, '2026-11', [
                ['id' => $this->dps->id, 'hash' => $this->dps->xml_hash],
                ['id' => $this->dps->id + 1000000, 'hash' => str_repeat('a', 64)],
            ]);
            $this->fail('A seleção inválida deveria impedir a fila.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame(0, \App\Models\Nfse\FilaHomologacao::daEmpresa($this->empresa)->count());
            $this->assertSame(0, TransmissaoRestrita::daEmpresa($this->empresa)->count());
        }
    }

    public function test_tela_fila_exige_habilitacao_e_confirmacao_explicita(): void
    {
        session(['empresa_selecionada_id' => $this->empresa->id]);
        config(['automacao_fiscal.nfse_dps_fila_enabled' => false]);
        \Livewire\Livewire::test(\App\Livewire\Nfse\FilaHomologacao::class)
            ->set('mes', '2026-11')->assertSee('Fila externa bloqueada')
            ->call('selecionar', $this->dps->id)->assertForbidden();
        config(['automacao_fiscal.nfse_dps_fila_enabled' => true]);
        \Livewire\Livewire::test(\App\Livewire\Nfse\FilaHomologacao::class)
            ->set('mes', '2026-11')->call('selecionar', $this->dps->id)
            ->call('aprovar')->assertHasErrors(['confirmacao' => 'accepted']);
        $this->assertSame(0, \App\Models\Nfse\FilaHomologacao::daEmpresa($this->empresa)->count());
    }


    private function dadosPerfil(): array
    {
        return [
            'regime' => 'simples_nacional', 'regApTribSN' => '1', 'municipio_prestacao' => '4308003',
            'iss' => 'tributado', 'iss_retido' => 'nao', 'aliquota_iss' => null,
            'regime_especial' => 'nao', 'deducoes_beneficios' => 'nao',
            'retencoes' => array_fill_keys(\App\Services\Nfse\PerfisTributariosNfse::RETENCOES, 'nao'),
            'ibscbs' => 'dispensado', 'cst' => null, 'cclass_trib' => null, 'ind_op' => null,
            'percentuais_tributos' => ['pTotTribFed' => '5.00', 'pTotTribEst' => '0.00', 'pTotTribMun' => '2.00'],
            'fundamento' => 'Condições fictícias para teste automatizado, não classificação legal.',
        ];
    }

    public function test_perfil_respeita_competencia_e_prioridade_do_servico(): void
    {
        $svc = app(\App\Services\Nfse\PerfisTributariosNfse::class);
        $dados = $this->dadosPerfil();
        $geral = $svc->cadastrar($this->empresa->id, null, '2026-10', $dados);
        $svc->cadastrar($this->empresa->id, null, '2026-12', array_replace($dados, ['municipio_prestacao' => '4306907']));
        $this->assertSame($geral->id, $svc->vigente($this->empresa, $this->nota->refresh())->id);
        $especifico = $svc->cadastrar($this->empresa->id, $this->nota->servico_id, '2026-11', array_replace($dados, ['municipio_prestacao' => '4306907']));
        $this->assertSame($especifico->id, $svc->vigente($this->empresa, $this->nota)->id);
        $this->assertSame('4306907', $svc->sugestao($especifico)['municipio_prestacao']);
        $this->assertSame('4308003', $geral->refresh()->dados['municipio_prestacao']);
        $this->expectException(ValidationException::class);
        $svc->cadastrar($this->empresa->id, null, '2026-10', $dados);
    }

    public function test_perfil_com_ibscbs_exigido_impede_preparo_mesmo_com_confirmacao_manual(): void
    {
        $svc = app(\App\Services\Nfse\PerfisTributariosNfse::class);
        $svc->cadastrar($this->empresa->id, null, '2026-10', $this->dadosPerfil());
        $especifico = $svc->cadastrar($this->empresa->id, $this->nota->servico_id, '2026-11', array_replace($this->dadosPerfil(), [
            'ibscbs' => 'exigido', 'cst' => '000', 'cclass_trib' => '000001',
        ]));
        $fiscal = array_replace($svc->sugestao($especifico), ['escopo_confirmado' => true, 'ibscbs_nao_exigido' => true]);
        $this->expectException(ValidationException::class);
        app(NumeracaoDpsHomologacao::class)->preparar($this->empresa->id, $this->nota->id, $fiscal);
    }

    public function test_perfil_novo_impede_aprovacao_de_dps_anterior(): void
    {
        app(\App\Services\Nfse\PerfisTributariosNfse::class)->cadastrar($this->empresa->id, null, '2026-11', $this->dadosPerfil());
        $mock = $this->createMock(TransporteSefinRestrita::class);
        $mock->expects($this->never())->method('enviar');
        $this->app->instance(TransporteSefinRestrita::class, $mock);
        $this->expectException(ValidationException::class);
        $this->aprovar(app(EmissaoRestrita::class));
    }

    public function test_perfil_prefill_na_homologacao_sem_confirmacao_automatica(): void
    {
        session(['empresa_selecionada_id' => $this->empresa->id]);
        app(\App\Services\Nfse\PerfisTributariosNfse::class)->cadastrar($this->empresa->id, null, '2026-11', $this->dadosPerfil());
        \Livewire\Livewire::test(\App\Livewire\Nfse\PerfisTributarios::class)->assertSee('Histórico de versões')->assertSee('Padrão da empresa');
        \Livewire\Livewire::test(\App\Livewire\Nfse\Homologacao::class)->set('mes', '2026-11')
            ->call('selecionarNota', $this->nota->id)->assertSet('fiscal.municipio_prestacao', '4308003')
            ->assertSet('fiscal.percentuais_tributos.pTotTribFed', '5.00')
            ->assertSet('fiscal.escopo_confirmado', false)->assertSet('fiscal.ibscbs_nao_exigido', false);
    }

    public function test_perfil_nao_permite_servico_de_outro_prestador(): void
    {
        $outra = Empresa::factory()->create(['empresa_operadora_id' => $this->empresa->empresa_operadora_id, 'ativo' => true]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(\App\Services\Nfse\PerfisTributariosNfse::class)->cadastrar($outra->id, $this->nota->servico_id, '2026-11', $this->dadosPerfil());
    }

    public function test_perfil_rejeita_cst_e_classificacao_incoerentes(): void
    {
        $this->expectException(ValidationException::class);
        app(\App\Services\Nfse\PerfisTributariosNfse::class)->cadastrar($this->empresa->id, null, '2026-11', array_replace($this->dadosPerfil(), [
            'ibscbs' => 'exigido', 'cst' => '000', 'cclass_trib' => '200001',
        ]));
    }


    private function catalogoFicticio(string $versao = 'Teste A', string $data = '2026-10-01', string $descricao = 'Permitido teste'): object
    {
        $csv = implode(';', \App\Services\Nfse\CatalogoTributarioNfse::CABECALHO)."\n"
            ."000;CST fictício;000001;".$descricao.";2026-01-01;;1;1;0.00;0.00\n"
            ."000;CST fictício;000002;Vedado teste;2026-01-01;;0;1;;\n"
            ."000;CST fictício;000003;Expirado teste;2026-01-01;2026-11-15;1;1;;\n";
        return app(\App\Services\Nfse\CatalogoTributarioNfse::class)->importar($csv, 'svrs', $versao, $data, hash('sha256', $csv));
    }

    public function test_catalogo_filtra_nfse_e_mes_e_preserva_historico(): void
    {
        $catalogo = app(\App\Services\Nfse\CatalogoTributarioNfse::class);
        $v = $this->catalogoFicticio();
        $this->assertEquals($v->id, $this->catalogoFicticio()->id);
        $this->assertSame(['000001'], $catalogo->buscar($v->id, '2026-11', '000')->pluck('cclass_trib')->all());
        $this->assertCount(2, $catalogo->consulta($v->id, '2026-10')->get());
        $novo = $this->catalogoFicticio('Teste B', '2026-10-30', 'Descrição revisada teste');
        $this->assertEquals($novo->id, $catalogo->atual()->id);
        $this->assertSame('Permitido teste', $catalogo->consulta($v->id, '2026-11')->first()->descricao);
    }

    public function test_perfil_vincula_classificacao_e_recusa_codigo_vedado(): void
    {
        $v = $this->catalogoFicticio();
        $dados = array_replace($this->dadosPerfil(), ['ibscbs'=>'exigido', 'cst'=>'000', 'cclass_trib'=>'000001']);
        $svc = app(\App\Services\Nfse\PerfisTributariosNfse::class);
        $perfil = $svc->cadastrar($this->empresa->id, null, '2026-11', $dados, (int) $v->id);
        $item = app(\App\Services\Nfse\CatalogoTributarioNfse::class)->consulta($v->id, '2026-11')->first();
        $this->assertEquals($item->id, $perfil->classificacao_catalogo_id);
        $this->expectException(ValidationException::class);
        $svc->cadastrar($this->empresa->id, null, '2026-12', array_replace($dados, ['cclass_trib'=>'000002']));
    }

    public function test_perfil_recusa_versao_desatualizada_entre_selecao_e_salvamento(): void
    {
        $v = $this->catalogoFicticio();
        $this->catalogoFicticio('Teste B', '2026-10-30', 'Revisado teste');
        $this->expectException(ValidationException::class);
        app(\App\Services\Nfse\PerfisTributariosNfse::class)->cadastrar($this->empresa->id, null, '2026-11',
            array_replace($this->dadosPerfil(), ['ibscbs'=>'exigido','cst'=>'000','cclass_trib'=>'000001']), (int) $v->id);
    }

    public function test_busca_e_selecao_na_tela_nao_define_enquadramento(): void
    {
        $v = $this->catalogoFicticio();
        $item = app(\App\Services\Nfse\CatalogoTributarioNfse::class)->consulta($v->id, '2026-11')->first();
        session(['empresa_selecionada_id' => $this->empresa->id]);
        \Livewire\Livewire::test(\App\Livewire\Nfse\PerfisTributarios::class)
            ->set('vigencia', '2026-11')->set('buscaClassificacao', 'Permitido')
            ->assertSee('Permitido teste')->assertDontSee('Vedado teste')
            ->call('selecionarClassificacao', $item->id)->assertSet('form.cst', '000')
            ->assertSet('form.cclass_trib', '000001')->assertSet('form.ibscbs', '')->assertSet('confirmado', false);
    }

}
