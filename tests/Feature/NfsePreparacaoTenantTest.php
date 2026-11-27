<?php

namespace Tests\Feature;

use App\Livewire\Nfse\CentralEmissao;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Nfse\Nota;
use App\Models\Nfse\Tomador;
use App\Models\User;
use App\Services\Nfse\PreparacaoNfse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NfsePreparacaoTenantTest extends TestCase
{
    use DatabaseTransactions;

    private Empresa $empresa;
    private Empresa $outraEmpresa;
    private Empresa $empresaOutroEscritorio;
    private User $admin;
    private User $operador;
    private PreparacaoNfse $service;

    protected function setUp(): void
    {
        parent::setUp();
        $a = EmpresasOperadora::factory()->create(['ativo' => true]);
        $b = EmpresasOperadora::factory()->create(['ativo' => true]);
        $this->empresa = Empresa::factory()->create(['empresa_operadora_id' => $a->id, 'ativo' => true]);
        $this->outraEmpresa = Empresa::factory()->create(['empresa_operadora_id' => $a->id, 'ativo' => true]);
        $this->empresaOutroEscritorio = Empresa::factory()->create(['empresa_operadora_id' => $b->id, 'ativo' => true]);
        $this->admin = User::factory()->create(['empresa_operadora_id' => $a->id, 'role' => 'admin', 'ativo' => true]);
        $this->operador = User::factory()->create(['empresa_operadora_id' => $a->id, 'role' => 'operador', 'ativo' => true]);
        $this->actingAs($this->admin);
        $this->service = app(PreparacaoNfse::class);
    }

    private function tomador(int $empresaId): Tomador
    {
        return $this->service->salvarTomador($empresaId, null, ['documento' => '529.982.247-25', 'nome' => 'Tomador fictício', 'ativo' => true]);
    }

    private function habilitar(): void
    {
        $this->service->salvarConfiguracao($this->empresa->id, ['habilitado_preparo' => true]);
    }

    private function dadosNota(): array
    {
        $tomador = $this->tomador($this->empresa->id);
        $servico = $this->service->salvarServico($this->empresa->id, null, ['codigo' => 'S1', 'nome' => 'Serviço teste', 'descricao' => 'Serviço teste', 'codigo_tributacao_nacional' => '170101', 'ativo' => true]);
        return ['tomador_id' => $tomador->id, 'servico_id' => $servico->id, 'competencia' => '2026-11-01', 'descricao' => 'Serviço teste', 'valor' => '123.45'];
    }

    public function test_documento_normalizado_pode_existir_em_prestadores_distintos(): void
    {
        $a = $this->tomador($this->empresa->id);
        $b = $this->tomador($this->outraEmpresa->id);
        $this->assertSame('52998224725', $a->documento);
        $this->assertNotEquals($a->id, $b->id);
        $this->expectException(ValidationException::class);
        $this->tomador($this->empresa->id);
    }

    public function test_nao_permite_cadastro_em_outro_escritorio(): void
    {
        $this->expectException(ValidationException::class);
        $this->tomador($this->empresaOutroEscritorio->id);
    }

    public function test_nao_permite_editar_tomador_de_outro_prestador(): void
    {
        $tomador = $this->tomador($this->outraEmpresa->id);
        $this->expectException(ModelNotFoundException::class);
        $this->service->salvarTomador($this->empresa->id, $tomador->id, ['documento' => $tomador->documento, 'nome' => 'Alterado', 'ativo' => true]);
    }

    public function test_operador_nao_habilita_preparo(): void
    {
        $this->actingAs($this->operador);
        $this->expectException(HttpException::class);
        $this->habilitar();
    }

    public function test_rascunho_exige_habilitacao(): void
    {
        $dados = $this->dadosNota();
        $this->expectException(ValidationException::class);
        $this->service->salvarRascunho($this->empresa->id, (string) Str::uuid(), $dados);
    }

    public function test_repetir_intencao_nao_duplica_e_nunca_autoriza_nota(): void
    {
        $this->habilitar();
        $dados = $this->dadosNota();
        $id = (string) Str::uuid();
        $this->actingAs($this->operador);
        $a = $this->service->salvarRascunho($this->empresa->id, $id, $dados);
        $b = $this->service->salvarRascunho($this->empresa->id, $id, $dados);
        $this->assertSame($a->id, $b->id);
        $this->assertSame('rascunho', $a->status);
        $this->assertSame('123.45', $a->valor);
        $this->assertSame('52998224725', $a->snapshot['tomador']['documento']);
        $this->assertSame(1, Nota::daEmpresa($this->empresa)->where('intencao', $id)->count());
    }

    public function test_nao_permite_tomador_de_outro_prestador_na_nota(): void
    {
        $this->habilitar();
        $dados = $this->dadosNota();
        $dados['tomador_id'] = $this->tomador($this->outraEmpresa->id)->id;
        $this->expectException(ModelNotFoundException::class);
        $this->service->salvarRascunho($this->empresa->id, (string) Str::uuid(), $dados);
    }

    public function test_painel_lista_apenas_tomadores_do_prestador_selecionado(): void
    {
        $this->tomador($this->empresa->id);
        $this->service->salvarTomador($this->outraEmpresa->id, null, ['documento' => '52998224725', 'nome' => 'Tomador oculto', 'ativo' => true]);
        session(['empresa_selecionada_id' => $this->empresa->id]);
        Livewire::test(CentralEmissao::class)->call('mudarAba', 'tomadores')
            ->assertSee('Tomador fictício')->assertDontSee('Tomador oculto');
    }

    public function test_cadastro_servico_pesquisa_e_seleciona_codigos_do_anexo_b(): void
    {
        session(['empresa_selecionada_id' => $this->empresa->id]);
        Livewire::test(CentralEmissao::class)->call('mudarAba', 'servicos')
            ->call('abrirPesquisaTributaria', 'nacional')
            ->set('buscaCodigoTributario', 'advocacia')->assertSee('171401')
            ->call('selecionarCodigoTributario', 'nacional', '171401')
            ->assertSet('form.codigo_tributacao_nacional', '171401')
            ->assertSee('Advocacia')
            ->call('abrirPesquisaTributaria', 'nbs')
            ->set('buscaCodigoTributario', '1.0101.11.00')
            ->call('selecionarCodigoTributario', 'nbs', '101011100')
            ->assertSet('form.nbs', '101011100')
            ->call('limparNbs')->assertSet('form.nbs', '');
    }

    public function test_catalogo_municipal_isola_escritorio_e_municipio(): void
    {
        $this->empresa->update(['codigo_municipio_ibge' => '4316907']);
        $this->outraEmpresa->update(['codigo_municipio_ibge' => '4304606']);
        $this->empresaOutroEscritorio->update(['codigo_municipio_ibge' => '4316907']);
        $svc = app(\App\Services\Nfse\CatalogoMunicipalNfse::class);
        $this->assertSame(1, $svc->importar($this->empresa->id, "17.14.01.000|Advocacia\n17.14.01.123|Complemento fictício de teste\n"));
        $this->assertSame('123', $svc->consultar($this->empresa->id, '171401')[0]['municipal']);
        $this->assertSame([], $svc->consultar($this->empresa->id, '010101'));
        $this->assertSame([], $svc->consultar($this->outraEmpresa->id, '171401'));
        $this->actingAs(User::factory()->admin()->create(['empresa_operadora_id' => $this->empresaOutroEscritorio->empresa_operadora_id, 'ativo' => true]));
        $this->assertSame([], $svc->consultar($this->empresaOutroEscritorio->id, '171401'));
    }

    public function test_operador_nao_substitui_catalogo_municipal(): void
    {
        $this->empresa->update(['codigo_municipio_ibge' => '4316907']);
        $this->actingAs($this->operador);
        $this->expectException(HttpException::class);
        app(\App\Services\Nfse\CatalogoMunicipalNfse::class)->importar($this->empresa->id, '17.14.01.123|Complemento fictício');
    }

}
