<?php

namespace Tests\Feature;

use App\Livewire\AjustesLancamentosMassa;
use App\Models\AjusteLancamentoLote;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Importacao;
use App\Models\Lancamento;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class TenantAjusteLancamentoMassaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ajuste_em_massa_nao_marca_como_conferido_e_reversao_restaura_historico(): void
    {
        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'role' => 'admin',
        ]);
        $empresa = Empresa::factory()->create(['empresa_operadora_id' => $operadora->id]);

        $this->actingAs($user);

        $importacao = Importacao::create([
            'nome_arquivo' => 'ajuste.ofx',
            'nome' => 'Formato OFX',
            'tipo' => 'avancado',
            'status' => 'concluida',
            'usuario' => $user->name,
            'empresa_id' => $empresa->id,
        ]);

        $lancamento = Lancamento::create([
            'data' => '2026-08-01',
            'historico' => 'HISTORICO ORIGINAL',
            'valor' => 150.00,
            'importacao_id' => $importacao->id,
            'empresa_id' => $empresa->id,
            'conferido' => false,
            'processado' => true,
        ]);

        Livewire::test(AjustesLancamentosMassa::class)
            ->set('importacaoId', (string) $importacao->id)
            ->set('alterarHistorico', true)
            ->set('novoHistorico', 'HISTORICO AJUSTADO')
            ->call('aplicarAlteracoes')
            ->assertHasNoErrors();

        $lancamento->refresh();
        $this->assertSame('HISTORICO AJUSTADO', $lancamento->historico);
        $this->assertFalse((bool) $lancamento->conferido);

        $lote = AjusteLancamentoLote::query()
            ->where('importacao_id', $importacao->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($lote);

        Livewire::test(AjustesLancamentosMassa::class)
            ->set('importacaoId', (string) $importacao->id)
            ->call('prepararReversao', $lote->id)
            ->call('confirmarReversao')
            ->assertHasNoErrors();

        $lancamento->refresh();
        $this->assertSame('HISTORICO ORIGINAL', $lancamento->historico);
        $this->assertFalse((bool) $lancamento->conferido);
    }
}
