<?php

namespace Tests\Feature;

use App\Jobs\Nfse\ProcessarSimulacaoNfseJob;
use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Nfse\Lote;
use App\Models\Nfse\Nota;
use App\Models\Nfse\SimulacaoItem;
use App\Models\User;
use App\Services\Nfse\AcessoEmissaoNfse;
use App\Services\Nfse\ConferenciaNfse;
use App\Services\Nfse\PreparacaoNfse;
use App\Services\Nfse\ProcessamentoSimuladoNfse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NfseAcessoSimulacaoTenantTest extends TestCase
{
    use DatabaseTransactions;

    private Empresa $empresa;
    private Empresa $outra;
    private User $admin;
    private AcessoEmissaoNfse $acesso;
    private ProcessamentoSimuladoNfse $simulador;

    protected function setUp(): void
    {
        parent::setUp();
        $a = EmpresasOperadora::factory()->create(['ativo' => true]);
        $b = EmpresasOperadora::factory()->create(['ativo' => true]);
        $this->empresa = Empresa::factory()->create(['empresa_operadora_id' => $a->id, 'cnpj' => '11222333000181', 'ativo' => true]);
        $this->outra = Empresa::factory()->create(['empresa_operadora_id' => $b->id, 'ativo' => true]);
        $this->admin = User::factory()->admin()->create(['empresa_operadora_id' => $a->id, 'ativo' => true]);
        $this->actingAs($this->admin);
        $this->acesso = app(AcessoEmissaoNfse::class);
        $this->simulador = app(ProcessamentoSimuladoNfse::class);
        Bus::fake([ProcessarSimulacaoNfseJob::class]);
    }

    private function certificado(Empresa $empresa, bool $escritorio): CertificadoDigital
    {
        return CertificadoDigital::create(['empresa_operadora_id' => $empresa->empresa_operadora_id,
            'empresa_id' => $escritorio ? null : $empresa->id, 'nome' => 'A1 teste', 'tipo' => 'A1',
            'arquivo_path' => 'teste-inexistente.pfx', 'senha_criptografada' => 'apenas-teste',
            'documento_titular' => $escritorio ? '52998224725' : $empresa->cnpj,
            'valido_de' => now()->subDay(), 'valido_ate' => now()->addMonth(), 'ativo' => true]);
    }

    private function lote(): Lote
    {
        $preparo = app(PreparacaoNfse::class);
        $preparo->salvarConfiguracao($this->empresa->id, ['habilitado_preparo' => true]);
        $t = $preparo->salvarTomador($this->empresa->id, null, ['documento' => '52998224725', 'nome' => 'Teste', 'ativo' => true]);
        $s = $preparo->salvarServico($this->empresa->id, null, ['codigo' => 'S1', 'nome' => 'Teste', 'descricao' => 'Teste', 'codigo_tributacao_nacional' => '170101', 'ativo' => true]);
        $versoes = [];
        foreach (['10.01', '10.02', '10.03'] as $valor) {
            $n = $preparo->salvarRascunho($this->empresa->id, (string) Str::uuid(), ['tomador_id' => $t->id, 'servico_id' => $s->id, 'competencia' => '2026-11-01', 'descricao' => 'Teste', 'valor' => $valor])->refresh();
            $versoes[$n->id] = $n->versao;
        }
        return app(ConferenciaNfse::class)->prepararLote($this->empresa->id, '2026-11', $versoes, '');
    }

    public function test_senha_criptografada_oculta_e_preservada_sem_reexibir(): void
    {
        $dados = ['modo' => 'senha_propria', 'canal' => 'portal', 'login' => '11222333000181', 'senha' => 'senha-ficticia'];
        $r = $this->acesso->salvar($this->empresa->id, $dados)->refresh();
        $this->assertNotSame('senha-ficticia', $r->getRawOriginal('senha_criptografada'));
        $this->assertNotSame('11222333000181', $r->getRawOriginal('usuario_criptografado'));
        $this->assertSame('senha-ficticia', $r->senha_criptografada);
        $this->assertArrayNotHasKey('senha_criptografada', $r->toArray());
        $this->assertArrayNotHasKey('usuario_criptografado', $r->toArray());
        $r = $this->acesso->salvar($this->empresa->id, ['modo' => 'senha_propria', 'canal' => 'portal'])->refresh();
        $this->assertSame('senha-ficticia', $r->senha_criptografada);
        $this->assertSame('pendente', $r->status_validacao);
    }

    public function test_senha_nao_pode_ser_usada_na_api(): void
    {
        $this->expectException(ValidationException::class);
        $this->acesso->salvar($this->empresa->id, ['modo' => 'senha_propria', 'canal' => 'api', 'login' => '11222333000181', 'senha' => 'teste']);
    }

    public function test_certificado_do_escritorio_configura_representacao_sem_validar_procuracao(): void
    {
        $cert = $this->certificado($this->empresa, true);
        $r = $this->acesso->salvar($this->empresa->id, ['modo' => 'certificado_representante', 'canal' => 'api', 'certificado_digital_id' => $cert->id]);
        $this->assertEquals($cert->id, $r->certificado_digital_id);
        $this->assertSame('11222333000181', $r->documento_prestador);
        $this->assertSame('pendente', $r->status_validacao);
    }

    public function test_certificado_de_outro_escritorio_nao_e_aceito(): void
    {
        $cert = $this->certificado($this->outra, true);
        $this->expectException(ValidationException::class);
        $this->acesso->salvar($this->empresa->id, ['modo' => 'certificado_representante', 'canal' => 'portal', 'certificado_digital_id' => $cert->id]);
    }

    public function test_operador_nao_configura_credenciais(): void
    {
        $this->actingAs(User::factory()->create(['empresa_operadora_id' => $this->empresa->empresa_operadora_id, 'role' => 'operador', 'ativo' => true]));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->acesso->salvar($this->empresa->id, ['modo' => 'senha_propria', 'canal' => 'portal']);
    }

    public function test_simulacao_mista_idempotente_e_sem_mudar_notas(): void
    {
        $lote = $this->lote();
        $sim = $this->simulador->aprovar($this->empresa->id, $lote->id, 'misto');
        Bus::assertDispatched(ProcessarSimulacaoNfseJob::class);
        $args = [(int) $this->empresa->empresa_operadora_id, $this->empresa->id, $sim->id];
        auth()->logout();
        $this->simulador->processar(...$args);
        $this->simulador->processar(...$args);
        $this->actingAs($this->admin);
        $this->assertSame('concluida', $sim->refresh()->status);
        $itens = SimulacaoItem::daEmpresa($this->empresa)->where('simulacao_id', $sim->id)->orderBy('nota_id')->get();
        $this->assertSame(['simulada_rejeitada', 'simulada_incerta', 'simulada_ok'], $itens->pluck('status')->all());
        $this->simulador->reconciliar($this->empresa->id, $itens[1]->id);
        $this->assertSame('simulada_ok', $itens[1]->refresh()->status);
        $this->assertEquals($this->admin->id, $itens[1]->reconciliado_por);
        $this->assertSame('preparado', $lote->refresh()->status);
        $this->assertSame(3, Nota::daEmpresa($this->empresa)->where('lote_id', $lote->id)->where('status', 'em_lote')->count());
    }

    public function test_job_com_tenant_incorreto_nao_processa(): void
    {
        $sim = $this->simulador->aprovar($this->empresa->id, $this->lote()->id, 'sucesso');
        $this->simulador->processar((int) $this->outra->empresa_operadora_id, $this->empresa->id, $sim->id);
        $this->assertSame('pendente', $sim->refresh()->status);
        $this->assertSame(0, SimulacaoItem::daEmpresa($this->empresa)->count());
    }

    public function test_desfazer_lote_antes_do_job_cancela_simulacao(): void
    {
        $lote = $this->lote();
        $sim = $this->simulador->aprovar($this->empresa->id, $lote->id, 'sucesso');
        app(ConferenciaNfse::class)->desfazerLote($this->empresa->id, $lote->id);
        $this->simulador->processar((int) $this->empresa->empresa_operadora_id, $this->empresa->id, $sim->id);
        $this->assertSame('cancelada', $sim->refresh()->status);
        $this->assertSame(0, SimulacaoItem::daEmpresa($this->empresa)->count());
    }
}
