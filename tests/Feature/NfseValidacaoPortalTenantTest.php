<?php

namespace Tests\Feature;

use App\Jobs\Nfse\ValidarAcessoPortalNfseJob;
use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Nfse\ValidacaoPortal;
use App\Models\User;
use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use App\Services\Nfse\AcessoEmissaoNfse;
use App\Services\Nfse\PortalWebNfseRunner;
use App\Services\Nfse\ValidacaoPortalNfse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class NfseValidacaoPortalTenantTest extends TestCase
{
    use DatabaseTransactions;

    private Empresa $empresa;
    private User $admin;
    private AcessoEmissaoNfse $acessos;
    private ValidacaoPortalNfse $service;
    private $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $o = EmpresasOperadora::factory()->create(['ativo' => true]);
        $this->empresa = Empresa::factory()->create(['empresa_operadora_id' => $o->id, 'cnpj' => '11222333000181', 'ativo' => true]);
        $this->admin = User::factory()->admin()->create(['empresa_operadora_id' => $o->id, 'ativo' => true]);
        $this->actingAs($this->admin);
        Bus::fake([ValidarAcessoPortalNfseJob::class]);
        $this->acessos = app(AcessoEmissaoNfse::class);
        $this->runner = Mockery::mock(PortalWebNfseRunner::class);
        $this->app->instance(PortalWebNfseRunner::class, $this->runner);
        $this->service = app(ValidacaoPortalNfse::class);
    }

    private function salvar(string $modo = 'senha_propria', string $senha = 'segredo-ficticio'): void
    {
        $this->acessos->salvar($this->empresa->id, ['modo' => $modo, 'canal' => 'portal',
            'login' => str_ends_with($modo, '_representante') ? '52998224725' : $this->empresa->cnpj, 'senha' => $senha]);
    }

    private function processar(ValidacaoPortal $r): void
    {
        auth()->logout();
        $this->service->processar((int) $this->empresa->empresa_operadora_id, $this->empresa->id, $r->id);
    }

    public function test_senha_propria_teste_em_fila_sem_segredos_e_idempotente(): void
    {
        $this->salvar();
        $r = $this->service->solicitar($this->empresa->id);
        $this->assertSame($r->id, $this->service->solicitar($this->empresa->id)->id);
        Bus::assertDispatched(ValidarAcessoPortalNfseJob::class, function ($job) {
            $this->assertStringNotContainsString('segredo-ficticio', serialize($job));
            return $job->connection === 'database' && $job->queue === 'default';
        });
        $this->runner->shouldReceive('testar')->once()->withArgs(fn ($v) => $v['password'] === 'segredo-ficticio' && $v['loginDocument'] === $this->empresa->cnpj)
            ->andReturn(['code' => 'ACCESS_CONFIRMED', 'identityConfirmed' => true, 'representationConfirmed' => false]);
        $this->processar($r);
        $this->processar($r);
        $this->assertSame('confirmada', $r->refresh()->status);
        $this->assertNotNull($r->concluida_em);
        $this->assertStringNotContainsString('segredo-ficticio', $r->toJson());
    }

    public function test_senha_representante_confirma_somente_com_representacao(): void
    {
        $this->salvar('senha_representante');
        $r = $this->service->solicitar($this->empresa->id);
        $this->runner->shouldReceive('testar')->once()->withArgs(fn ($v) => $v['companyDocument'] === $this->empresa->cnpj && $v['loginDocument'] === '52998224725')
            ->andReturn(['code' => 'ACCESS_CONFIRMED', 'identityConfirmed' => true, 'representationConfirmed' => true]);
        $this->processar($r);
        $this->assertSame('confirmada', $r->refresh()->status);
    }

    public function test_login_do_representante_sem_troca_de_empresa_nao_confirma(): void
    {
        $this->salvar('senha_representante');
        $r = $this->service->solicitar($this->empresa->id);
        $this->runner->shouldReceive('testar')->once()->andReturn(['code' => 'ACCESS_CONFIRMED', 'identityConfirmed' => true, 'representationConfirmed' => false]);
        $this->processar($r);
        $this->assertSame('nao_confirmada', $r->refresh()->status);
        $this->assertSame('IDENTITY_MISMATCH', $r->codigo);
    }

    public function test_credencial_trocada_antes_do_job_nao_e_testada(): void
    {
        $this->salvar();
        $r = $this->service->solicitar($this->empresa->id);
        $this->salvar('senha_propria', 'outra-ficticia');
        $this->runner->shouldNotReceive('testar');
        $this->processar($r);
        $this->assertSame('config_alterada', $r->refresh()->status);
    }

    public function test_troca_durante_login_invalida_resultado(): void
    {
        $this->salvar();
        $r = $this->service->solicitar($this->empresa->id);
        $this->runner->shouldReceive('testar')->once()->andReturnUsing(function () {
            $this->actingAs($this->admin);
            $this->salvar('senha_propria', 'nova-ficticia');
            auth()->logout();
            return ['code' => 'ACCESS_CONFIRMED', 'identityConfirmed' => true, 'representationConfirmed' => false];
        });
        $this->processar($r);
        $this->assertSame('config_alterada', $r->refresh()->status);
    }

    public function test_revogacao_do_usuario_antes_do_job_bloqueia_login(): void
    {
        $this->salvar();
        $r = $this->service->solicitar($this->empresa->id);
        $this->admin->update(['ativo' => false]);
        $this->runner->shouldNotReceive('testar');
        $this->processar($r);
        $this->assertSame('config_alterada', $r->refresh()->status);
    }

    public function test_tenant_errado_nao_altera_tentativa_e_empresa_inativa_bloqueia(): void
    {
        $this->salvar();
        $r = $this->service->solicitar($this->empresa->id);
        $this->runner->shouldNotReceive('testar');
        $this->service->processar(999999, $this->empresa->id, $r->id);
        $this->assertSame('pendente', $r->refresh()->status);
        $this->empresa->update(['ativo' => false]);
        $this->processar($r);
        $this->assertSame('config_alterada', $r->refresh()->status);
    }

    public function test_erro_tecnico_nao_vaza_segredos_nem_preserva_confirmacao_anterior(): void
    {
        $this->salvar();
        \App\Models\Nfse\AcessoEmissao::daEmpresa($this->empresa)->update(['status_validacao' => 'portal_confirmado']);
        $r = $this->service->solicitar($this->empresa->id);
        $this->assertSame('pendente', \App\Models\Nfse\AcessoEmissao::daEmpresa($this->empresa)->value('status_validacao'));
        $this->runner->shouldReceive('testar')->once()->andThrow(new \RuntimeException('segredo-ficticio'));
        $this->processar($r);
        $this->assertSame('nao_confirmada', $r->refresh()->status);
        $this->assertStringNotContainsString('segredo-ficticio', $r->toJson());
    }

    public function test_procuracao_vencida_nao_chega_ao_runner(): void
    {
        $this->salvar('senha_representante');
        $r = $this->service->solicitar($this->empresa->id);
        \App\Models\Nfse\AcessoEmissao::daEmpresa($this->empresa)->update(['procuracao_ate' => now()->subDay()->toDateString()]);
        $this->runner->shouldNotReceive('testar');
        $this->processar($r);
        $this->assertSame('config_alterada', $r->refresh()->status);
    }

    public function test_acesso_api_nao_e_usado_para_login_web(): void
    {
        $this->salvar();
        \App\Models\Nfse\AcessoEmissao::daEmpresa($this->empresa)->update(['canal' => 'api']);
        $this->expectException(ValidationException::class);
        $this->service->solicitar($this->empresa->id);
    }

    public function test_certificado_proprio_e_do_representante_enviados_em_memoria_ao_runner(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'Fixture'], $key);
        $crt = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($crt, $cert);
        openssl_pkey_export($key, $privateKey);
        $certService = Mockery::mock(CertificadoDigitalService::class);
        $certService->shouldReceive('extrairPem')->twice()->andReturn(['cert' => $cert, 'key' => $privateKey]);
        $this->app->instance(CertificadoDigitalService::class, $certService);
        $service = $this->app->make(ValidacaoPortalNfse::class);
        foreach (['certificado_proprio', 'certificado_representante'] as $modo) {
            $representante = str_ends_with($modo, '_representante');
            $c = CertificadoDigital::create(['empresa_operadora_id' => $this->empresa->empresa_operadora_id,
                'empresa_id' => $representante ? null : $this->empresa->id, 'nome' => 'Fixture', 'tipo' => 'A1',
                'arquivo_path' => 'nao-lido.pfx', 'senha_criptografada' => 'ficticia', 'fingerprint' => openssl_x509_fingerprint($crt, 'sha256'),
                'documento_titular' => $representante ? '52998224725' : $this->empresa->cnpj,
                'valido_de' => now()->subDay(), 'valido_ate' => now()->addMonth(), 'ativo' => true]);
            $this->actingAs($this->admin);
            $this->acessos->salvar($this->empresa->id, ['modo' => $modo, 'canal' => 'portal', 'certificado_digital_id' => $c->id]);
            $r = $service->solicitar($this->empresa->id);
            $this->runner->shouldReceive('testar')->once()->withArgs(fn ($v) => $v['certificate']['cert'] === $cert && ! isset($v['password']))
                ->andReturn(['code' => 'ACCESS_CONFIRMED', 'identityConfirmed' => true, 'representationConfirmed' => $representante]);
            auth()->logout();
            $service->processar((int) $this->empresa->empresa_operadora_id, $this->empresa->id, $r->id);
            $this->assertSame('confirmada', $r->refresh()->status);
        }
    }

    public function test_tela_indica_credenciais_salvas_sem_expor_login_ou_senha(): void
    {
        $this->salvar('senha_representante');
        session(['empresa_selecionada_id' => $this->empresa->id]);
        \Livewire\Livewire::test(\App\Livewire\Nfse\Acessos::class)
            ->assertSee('Usuário e senha cadastrados')
            ->assertSee('Cadastrada e protegida')
            ->assertSee('4725')
            ->assertDontSee('52998224725')
            ->assertDontSee('segredo-ficticio')
            ->set('modo', 'senha_propria')
            ->assertSee('Usuário e senha não cadastrados ou indisponíveis nesta modalidade')
            ->assertSee('Há alterações na configuração que ainda não foram salvas');
    }

    public function test_log_portal_mostra_etapa_e_permite_download_sem_segredos(): void
    {
        $this->salvar();
        $r = $this->service->solicitar($this->empresa->id);
        $this->runner->shouldReceive('testar')->once()->andReturn([
            'code' => 'LOGIN_REJECTED', 'identityConfirmed' => false, 'representationConfirmed' => false,
            'diagnostico' => ['etapa' => 'confirmar_identidade', 'codigo' => 'LOGIN_REJECTED', 'html' => 'segredo-ficticio'],
        ]);
        $this->processar($r);
        $r->refresh();
        $this->assertSame('confirmar_identidade', $r->diagnostico_tecnico['etapa']);
        $this->assertSame('LOGIN_REJECTED', $r->diagnostico_tecnico['codigo']);
        $this->assertArrayNotHasKey('html', $r->diagnostico_tecnico);
        $log = json_encode(\App\Services\Nfse\LogTesteAcessoNfse::relatorio($r));
        $this->assertStringNotContainsString('segredo-ficticio', $log);
        $this->assertStringNotContainsString($r->config_hash, $log);
        $this->assertStringNotContainsString($this->empresa->cnpj, $log);
        $this->actingAs($this->admin);
        session(['empresa_selecionada_id' => $this->empresa->id]);
        \Livewire\Livewire::test(\App\Livewire\Nfse\Acessos::class)
            ->assertSee('LOGIN_REJECTED')->assertSee('Baixar log (.json)')
            ->call('baixarLog', 'portal', $r->id)->assertFileDownloaded('nfse-teste-portal-'.$r->id.'.json');
    }

    public function test_log_de_outra_empresa_nao_pode_ser_baixado(): void
    {
        $this->salvar();
        $r = $this->service->solicitar($this->empresa->id);
        $outra = Empresa::factory()->create(['empresa_operadora_id' => $this->empresa->empresa_operadora_id, 'ativo' => true]);
        session(['empresa_selecionada_id' => $outra->id]);
        \Livewire\Livewire::test(\App\Livewire\Nfse\Acessos::class)
            ->call('baixarLog', 'portal', $r->id)->assertNotFound();
    }

    public function test_log_api_preserva_apenas_detalhes_tecnicos_permitidos(): void
    {
        $this->assertSame([
            'etapa' => 'consultar_sefin', 'codigo' => 'CURL_SEFIN', 'duracao_ms' => 1200, 'curl' => 28,
        ], \App\Services\Nfse\LogTesteAcessoNfse::filtrar([
            'etapa' => 'consultar_sefin', 'codigo' => 'CURL_SEFIN', 'duracao_ms' => 1200, 'curl' => 28,
            'url' => 'chave-secreta', 'password' => 'segredo-ficticio', 'http' => 'segredo-ficticio',
        ]));
    }

}
