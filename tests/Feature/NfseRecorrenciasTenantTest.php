<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Nfse\Nota;
use App\Models\User;
use App\Services\Nfse\ConferenciaNfse;
use App\Services\Nfse\PreparacaoNfse;
use App\Services\Nfse\RecorrenciasNfse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NfseRecorrenciasTenantTest extends TestCase
{
    use DatabaseTransactions;

    private Empresa $empresa;
    private RecorrenciasNfse $service;
    private PreparacaoNfse $preparo;
    private array $dados;

    protected function setUp(): void
    {
        parent::setUp();
        $a = EmpresasOperadora::factory()->create(['ativo' => true]);
        $this->empresa = Empresa::factory()->create(['empresa_operadora_id' => $a->id, 'ativo' => true]);
        $this->actingAs(User::factory()->create(['empresa_operadora_id' => $a->id, 'role' => 'admin', 'ativo' => true]));
        $this->preparo = app(PreparacaoNfse::class);
        $this->service = app(RecorrenciasNfse::class);
        $this->preparo->salvarConfiguracao($this->empresa->id, ['habilitado_preparo' => true]);
        $t = $this->preparo->salvarTomador($this->empresa->id, null, ['documento' => '52998224725', 'nome' => 'Tomador teste', 'ativo' => true]);
        $s = $this->preparo->salvarServico($this->empresa->id, null, ['codigo' => 'S1', 'nome' => 'Serviço teste', 'descricao' => 'Serviço teste', 'codigo_tributacao_nacional' => '170101', 'ativo' => true]);
        $this->dados = ['referencia' => 'C1', 'tomador_id' => $t->id, 'servico_id' => $s->id, 'inicio' => '2026-11', 'fim' => null, 'dia' => 31, 'valor' => '100.01', 'descricao' => 'Mensalidade {mes}/{ano}'];
    }

    private function gerar(string $mes): int
    {
        $p = $this->service->previa($this->empresa->id, $mes);
        return $this->service->gerar($this->empresa->id, $mes, $p['conferencia']);
    }

    public function test_geracao_mensal_idempotente_e_data_ajustada(): void
    {
        $r = $this->service->cadastrar($this->empresa->id, $this->dados);
        $p = $this->service->previa($this->empresa->id, '2027-02');
        $this->assertSame('2027-02-28', $p['linhas'][0]['data']);
        $this->assertSame(1, $this->service->gerar($this->empresa->id, '2027-02', $p['conferencia']));
        $n = Nota::daEmpresa($this->empresa)->where('recorrencia_id', $r->id)->firstOrFail();
        $this->assertSame('rascunho', $n->status);
        $this->assertSame('Mensalidade 02/2027', $n->descricao);
        $this->assertSame(0, $this->service->previa($this->empresa->id, '2027-02')['aptas']);
        $this->expectException(ValidationException::class);
        $this->service->gerar($this->empresa->id, '2027-02', $p['conferencia']);
    }

    public function test_reajuste_respeita_vigencia_e_nao_altera_nota_antiga(): void
    {
        $r = $this->service->cadastrar($this->empresa->id, $this->dados);
        $this->gerar('2026-11');
        $this->service->reajustar($this->empresa->id, $r->id, '2026-12', ['valor' => '150.00', 'descricao' => 'Reajustada']);
        $this->gerar('2026-12');
        $this->assertSame(['100.01', '150.00'], Nota::daEmpresa($this->empresa)->orderBy('id')->pluck('valor')->all());
    }

    public function test_pausa_retomada_e_encerramento(): void
    {
        $r = $this->service->cadastrar($this->empresa->id, $this->dados);
        $this->service->pausar($this->empresa->id, $r->id, '2026-11', true);
        $this->assertSame('pausada', $this->service->previa($this->empresa->id, '2026-11')['linhas'][0]['status']);
        $this->service->pausar($this->empresa->id, $r->id, '2026-11', false);
        $this->assertSame(1, $this->gerar('2026-11'));
        $this->service->encerrar($this->empresa->id, $r->id, '2026-11');
        $this->assertSame([], $this->service->previa($this->empresa->id, '2026-12')['linhas']);
    }

    public function test_avulsa_com_mesmo_tomador_servico_bloqueia_geracao(): void
    {
        $this->service->cadastrar($this->empresa->id, $this->dados);
        $this->preparo->salvarRascunho($this->empresa->id, (string) Str::uuid(), ['tomador_id' => $this->dados['tomador_id'], 'servico_id' => $this->dados['servico_id'], 'competencia' => '2026-11-01', 'valor' => '99.00', 'descricao' => 'Avulsa']);
        $this->assertSame('outra_origem', $this->service->previa($this->empresa->id, '2026-11')['linhas'][0]['status']);
    }

    public function test_descartada_nao_e_recriada_e_mes_nao_pode_ser_trocado(): void
    {
        $this->service->cadastrar($this->empresa->id, $this->dados);
        $this->gerar('2026-11');
        $n = Nota::daEmpresa($this->empresa)->firstOrFail()->refresh();
        $c = app(ConferenciaNfse::class);
        try {
            $c->editar($this->empresa->id, '2026-11', [$n->id => $n->versao], ['competencia' => '2026-12-01']);
            $this->fail('A troca de competência deveria falhar.');
        } catch (ValidationException) {
            $this->assertSame('2026-11-30', $n->refresh()->competencia->toDateString());
        }
        $c->descartar($this->empresa->id, '2026-11', [$n->id => $n->versao]);
        $this->assertSame(0, $this->service->previa($this->empresa->id, '2026-11')['aptas']);
    }

    public function test_nao_permite_vincular_tomador_de_outro_prestador(): void
    {
        $outra = Empresa::factory()->create(['empresa_operadora_id' => $this->empresa->empresa_operadora_id, 'ativo' => true]);
        $this->preparo->salvarConfiguracao($outra->id, ['habilitado_preparo' => true]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service->cadastrar($outra->id, $this->dados);
    }
}
