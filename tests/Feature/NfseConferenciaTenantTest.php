<?php

namespace Tests\Feature;

use App\Livewire\Nfse\Conferencia;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Nfse\ConferenciaEvento;
use App\Models\Nfse\Lote;
use App\Models\Nfse\Nota;
use App\Models\User;
use App\Services\Nfse\ConferenciaNfse;
use App\Services\Nfse\PreparacaoNfse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class NfseConferenciaTenantTest extends TestCase
{
    use DatabaseTransactions;

    private Empresa $empresa;
    private Empresa $outra;
    private PreparacaoNfse $preparo;
    private ConferenciaNfse $conferencia;
    private array $dados;

    protected function setUp(): void
    {
        parent::setUp();
        $a = EmpresasOperadora::factory()->create(['ativo' => true]);
        $this->empresa = Empresa::factory()->create(['empresa_operadora_id' => $a->id, 'ativo' => true]);
        $this->outra = Empresa::factory()->create(['empresa_operadora_id' => $a->id, 'ativo' => true]);
        $this->actingAs(User::factory()->create(['empresa_operadora_id' => $a->id, 'role' => 'admin', 'ativo' => true]));
        $this->preparo = app(PreparacaoNfse::class);
        $this->conferencia = app(ConferenciaNfse::class);
        $this->dados = $this->configurar($this->empresa);
    }

    private function configurar(Empresa $empresa): array
    {
        $this->preparo->salvarConfiguracao($empresa->id, ['habilitado_preparo' => true]);
        $tomador = $this->preparo->salvarTomador($empresa->id, null, ['documento' => '52998224725', 'nome' => 'Tomador teste', 'ativo' => true]);
        $servico = $this->preparo->salvarServico($empresa->id, null, ['codigo' => 'S1', 'nome' => 'Serviço teste', 'descricao' => 'Serviço teste', 'codigo_tributacao_nacional' => '170101', 'ativo' => true]);
        return ['tomador_id' => $tomador->id, 'servico_id' => $servico->id, 'competencia' => '2026-11-01', 'descricao' => 'Serviço teste', 'valor' => '100.01'];
    }

    private function nota(array $alteracoes = []): Nota
    {
        return $this->preparo->salvarRascunho($this->empresa->id, (string) Str::uuid(), array_replace($this->dados, $alteracoes))->refresh();
    }

    public function test_edicao_em_massa_preserva_identidade_e_audita(): void
    {
        $a = $this->nota();
        $b = $this->nota(['valor' => '100.02']);
        $this->conferencia->editar($this->empresa->id, '2026-11', [$a->id => 1, $b->id => 1], ['valor' => '150.55']);
        $this->assertSame('150.55', $a->refresh()->valor);
        $this->assertSame('150.55', $b->refresh()->valor);
        $this->assertSame(2, $a->versao);
        $this->assertSame($this->dados['tomador_id'], $a->tomador_id);
        $evento = ConferenciaEvento::daEmpresa($this->empresa)->where('nota_id', $a->id)->firstOrFail();
        $this->assertSame('100.01', $evento->antes['valor']);
        $this->assertSame('150.55', $evento->depois['valor']);
    }

    public function test_versao_desatualizada_nao_sobrescreve_rascunho(): void
    {
        $nota = $this->nota();
        $this->conferencia->editar($this->empresa->id, '2026-11', [$nota->id => 1], ['valor' => '125.00']);
        $this->expectException(ValidationException::class);
        $this->conferencia->editar($this->empresa->id, '2026-11', [$nota->id => 1], ['valor' => '999.00']);
    }

    public function test_lote_preserva_snapshot_total_e_retorna_a_rascunho(): void
    {
        $a = $this->nota();
        $b = $this->nota(['valor' => '0.02']);
        $lote = $this->conferencia->prepararLote($this->empresa->id, '2026-11', [$a->id => 1, $b->id => 1], '');
        $this->assertSame('100.03', $lote->valor_total);
        $this->assertSame('preparado', $lote->status);
        $this->assertSame('em_lote', $a->refresh()->status);
        $this->assertEquals($lote->id, $a->lote_id);
        $this->assertCount(2, $lote->snapshot);
        $this->conferencia->desfazerLote($this->empresa->id, $lote->id);
        $this->assertSame('rascunho', $a->refresh()->status);
        $this->assertNull($a->lote_id);
        $this->assertSame(3, $a->versao);
        $this->assertSame('desfeito', $lote->refresh()->status);
        $this->assertCount(2, $lote->snapshot);
    }

    public function test_possivel_duplicidade_exige_justificativa(): void
    {
        $a = $this->nota();
        $b = $this->nota();
        try {
            $this->conferencia->prepararLote($this->empresa->id, '2026-11', [$a->id => 1, $b->id => 1], '');
            $this->fail('O lote deveria exigir justificativa.');
        } catch (ValidationException) {
            $this->assertSame(0, Lote::daEmpresa($this->empresa)->count());
        }
        $lote = $this->conferencia->prepararLote($this->empresa->id, '2026-11', [$a->id => 1, $b->id => 1], 'Dois atendimentos distintos.');
        $this->assertSame('Dois atendimentos distintos.', $lote->justificativa_alertas);
    }

    public function test_nao_permite_selecionar_nota_de_outra_empresa(): void
    {
        $nota = $this->preparo->salvarRascunho($this->outra->id, (string) Str::uuid(), $this->configurar($this->outra))->refresh();
        $this->expectException(ValidationException::class);
        $this->conferencia->prepararLote($this->empresa->id, '2026-11', [$nota->id => 1], '');
    }

    public function test_nao_permite_editar_item_em_lote(): void
    {
        $nota = $this->nota();
        $this->conferencia->prepararLote($this->empresa->id, '2026-11', [$nota->id => 1], '');
        $this->expectException(ValidationException::class);
        $this->conferencia->editar($this->empresa->id, '2026-11', [$nota->id => 2], ['valor' => '99.99']);
    }

    public function test_descartar_preserva_historico_e_nao_considera_na_comparacao(): void
    {
        $anterior = $this->nota(['competencia' => '2026-10-01', 'valor' => '80.00']);
        $atual = $this->nota();
        $this->assertSame('80.00', $this->conferencia->anterior($this->empresa, $atual));
        $this->conferencia->descartar($this->empresa->id, '2026-10', [$anterior->id => 1]);
        $this->assertSame('descartada', $anterior->refresh()->status);
        $this->assertNull($this->conferencia->anterior($this->empresa, $atual));
    }

    public function test_cadastro_alterado_exige_revisao_antes_do_lote(): void
    {
        $nota = $this->nota();
        $this->preparo->salvarTomador($this->empresa->id, $nota->tomador_id, ['documento' => '52998224725', 'nome' => 'Tomador revisado', 'ativo' => true]);
        try {
            $this->conferencia->prepararLote($this->empresa->id, '2026-11', [$nota->id => 1], '');
            $this->fail('O snapshot deveria exigir revisão.');
        } catch (ValidationException) {
            $this->assertSame('rascunho', $nota->refresh()->status);
        }
        $this->conferencia->editar($this->empresa->id, '2026-11', [$nota->id => 1], ['valor' => '100.01']);
        $lote = $this->conferencia->prepararLote($this->empresa->id, '2026-11', [$nota->id => 2], '');
        $this->assertSame('Tomador revisado', $lote->snapshot[0]['snapshot']['tomador']['nome']);
    }

    public function test_edicao_individual_pode_corrigir_competencia(): void
    {
        $nota = $this->nota();
        $this->conferencia->editar($this->empresa->id, '2026-11', [$nota->id => 1], ['competencia' => '2026-12-01']);
        $this->assertSame('2026-12-01', $nota->refresh()->competencia->toDateString());
        $this->assertSame(0, $this->conferencia->consulta($this->empresa->id, '2026-11')->count());
    }

    public function test_tela_seleciona_rascunhos_da_competencia(): void
    {
        $a = $this->nota();
        $this->nota(['competencia' => '2026-10-01']);
        Livewire::test(Conferencia::class, ['empresaId' => $this->empresa->id])->set('mes', '2026-11')->call('selecionarTodas')
            ->assertSet('selecionadas', [$a->id => 1])->assertSee('100,01');
    }
}
