<?php

namespace Tests\Feature;

use App\Livewire\Nfse\CentralEmissao;
use App\Livewire\Nfse\Painel;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\User;
use App\Services\Nfse\ConferenciaNfse;
use App\Services\Nfse\PainelNfse;
use App\Services\Nfse\PreparacaoNfse;
use App\Services\Nfse\RecorrenciasNfse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class NfsePainelTenantTest extends TestCase
{
    use DatabaseTransactions;

    private Empresa $empresa;
    private Empresa $segunda;
    private Empresa $outra;
    private User $admin;
    private PreparacaoNfse $preparo;
    private PainelNfse $painel;
    private array $dados;

    protected function setUp(): void
    {
        parent::setUp();
        $a = EmpresasOperadora::factory()->create(['ativo' => true]);
        $b = EmpresasOperadora::factory()->create(['ativo' => true]);
        $this->empresa = Empresa::factory()->create(['empresa_operadora_id' => $a->id, 'nome' => 'Prestador Alpha', 'razao_social' => 'Prestador Alpha', 'ativo' => true]);
        $this->segunda = Empresa::factory()->create(['empresa_operadora_id' => $a->id, 'nome' => 'Prestador Beta', 'razao_social' => 'Prestador Beta', 'ativo' => true]);
        Empresa::factory()->create(['empresa_operadora_id' => $a->id, 'ativo' => false]);
        $this->outra = Empresa::factory()->create(['empresa_operadora_id' => $b->id, 'nome' => 'Prestador oculto', 'ativo' => true]);
        $this->admin = User::factory()->create(['empresa_operadora_id' => $a->id, 'role' => 'admin', 'ativo' => true]);
        $this->actingAs($this->admin);
        $this->preparo = app(PreparacaoNfse::class);
        $this->painel = app(PainelNfse::class);
        $this->preparo->salvarConfiguracao($this->empresa->id, ['habilitado_preparo' => true]);
        $t = $this->preparo->salvarTomador($this->empresa->id, null, ['documento' => '52998224725', 'nome' => 'Tomador', 'ativo' => true]);
        $s = $this->preparo->salvarServico($this->empresa->id, null, ['codigo' => 'S1', 'nome' => 'Serviço', 'descricao' => 'Serviço', 'codigo_tributacao_nacional' => '170101', 'ativo' => true]);
        $this->dados = ['tomador_id' => $t->id, 'servico_id' => $s->id, 'competencia' => '2026-11-01', 'valor' => '100.01', 'descricao' => 'Avulsa'];
    }

    public function test_consolidado_independe_do_seletor_e_isola_escritorios(): void
    {
        session(['empresa_selecionada_id' => $this->segunda->id]);
        $q = $this->painel->consulta('2026-11');
        $this->assertEqualsCanonicalizing([$this->empresa->id, $this->segunda->id], (clone $q)->pluck('id')->all());
        $r = $this->painel->resumo($q);
        $this->assertEquals(2, $r->empresas);
        $this->assertEquals(1, $r->habilitadas);
        $this->assertEquals(0, $r->rascunhos);
        $this->assertEquals([$this->empresa->id], $this->painel->filtrar(clone $q, '', 'habilitadas')->pluck('id')->all());
    }

    public function test_somas_distinguem_rascunhos_lotes_descartadas_e_competencia(): void
    {
        $criar = fn (array $d) => $this->preparo->salvarRascunho($this->empresa->id, (string) Str::uuid(), array_replace($this->dados, $d))->refresh();
        $a = $criar([]);
        $b = $criar(['valor' => '50.02']);
        $d = $criar(['valor' => '999.00']);
        $criar(['competencia' => '2026-10-01']);
        $conf = app(ConferenciaNfse::class);
        $conf->prepararLote($this->empresa->id, '2026-11', [$b->id => $b->versao], '');
        $conf->descartar($this->empresa->id, '2026-11', [$d->id => $d->versao]);
        $r = $this->painel->resumo($this->painel->consulta('2026-11'));
        $this->assertEquals(1, $r->rascunhos);
        $this->assertEquals(1, $r->em_lote);
        $this->assertEquals(1, $r->lotes);
        $this->assertEquals(1, $r->descartadas);
        $this->assertSame('150.03', (string) $r->valor_preparado);
    }

    public function test_previsto_usa_ultimo_reajuste_e_exclui_pausa_sem_duplicar_ocorrencia(): void
    {
        $rec = app(RecorrenciasNfse::class);
        $dados = array_intersect_key($this->dados, array_flip(['tomador_id', 'servico_id', 'valor', 'descricao']));
        $r = $rec->cadastrar($this->empresa->id, $dados + ['referencia' => 'C1', 'inicio' => '2026-10', 'dia' => 5]);
        $p = $rec->cadastrar($this->empresa->id, $dados + ['referencia' => 'C2', 'inicio' => '2026-10', 'dia' => 5]);
        $rec->reajustar($this->empresa->id, $r->id, '2026-11', ['valor' => '150.03', 'descricao' => 'Mensalidade']);
        $rec->pausar($this->empresa->id, $p->id, '2026-11', true);
        $q = $this->painel->consulta('2026-11');
        $linha = $q->where('id', $this->empresa->id)->first();
        $this->assertEquals(1, $linha->previstas);
        $this->assertEquals(1, $linha->pausadas);
        $this->assertEquals(1, $linha->recorrencias_pendentes);
        $this->assertSame('150.03', (string) $linha->valor_previsto);
        $previa = $rec->previa($this->empresa->id, '2026-11');
        $rec->gerar($this->empresa->id, '2026-11', $previa['conferencia']);
        $linha = $this->painel->consulta('2026-11')->where('id', $this->empresa->id)->first();
        $this->assertEquals(0, $linha->recorrencias_pendentes);
        $this->assertEquals(1, $linha->rascunhos);
        $this->assertSame('150.03', (string) $linha->valor_previsto);
    }

    public function test_atalho_revalida_prestador_e_transporta_competencia(): void
    {
        Livewire::test(Painel::class)->set('mes', '2026-10')
            ->call('abrirEmpresa', $this->empresa->id, 'conferencia', 'em_lote')
            ->assertRedirect(route('nfse.central', ['mes' => '2026-10', 'aba' => 'conferencia', 'situacao' => 'em_lote']));
        $this->assertEquals($this->empresa->id, session('empresa_selecionada_id'));
        Livewire::withQueryParams(['mes' => '2026-10', 'aba' => 'conferencia', 'situacao' => 'em_lote'])
            ->test(CentralEmissao::class)->assertSet('competencia', '2026-10')->assertSet('aba', 'conferencia')->assertSet('situacaoInicial', 'em_lote');
    }

    public function test_atalho_nao_permite_outro_escritorio(): void
    {
        Livewire::test(Painel::class)->set('mes', '2026-11')->call('abrirEmpresa', $this->outra->id, 'notas')->assertHasErrors('empresa_id');
        $this->assertNotEquals($this->outra->id, session('empresa_selecionada_id'));
    }

    public function test_super_admin_sem_contexto_nao_le_dados_consolidados(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create(['ativo' => true]));
        session()->forget('operadora_context_id');
        Livewire::test(Painel::class)->assertSee('Selecione um escritório')->assertDontSee('Prestador Alpha');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->painel->consulta('2026-11');
    }
}
