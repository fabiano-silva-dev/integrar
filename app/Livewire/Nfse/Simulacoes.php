<?php

namespace App\Livewire\Nfse;

use App\Models\Nfse\Lote;
use App\Models\Nfse\Simulacao;
use App\Models\Nfse\SimulacaoItem;
use App\Services\Nfse\AcessoEmissaoNfse;
use App\Services\Nfse\PreparacaoNfse;
use App\Services\Nfse\ProcessamentoSimuladoNfse;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Simulacoes extends Component
{
    use WithPagination;

    protected $layout = 'components.layouts.app';
    #[Locked]
    public ?int $empresaId = null;
    #[Locked]
    public ?int $detalheId = null;
    public string $mes = '';
    public string $cenario = 'misto';

    public function mount(): void
    {
        abort_unless(app(PreparacaoNfse::class)->podeConfigurar(), 403);
        if ($id = session('empresa_selecionada_id')) {
            $this->empresaId = app(AcessoEmissaoNfse::class)->empresa((int) $id)->id;
        }
        $this->mes = now()->format('Y-m');
    }

    public function updatedMes(): void
    {
        $this->detalheId = null;
        $this->resetPage();
    }

    public function aprovar(int $loteId): void
    {
        abort_unless($this->empresaId, 422);
        $sim = app(ProcessamentoSimuladoNfse::class)->aprovar($this->empresaId, $loteId, $this->cenario);
        $this->detalheId = $sim->id;
        session()->flash('nfse_sim_mensagem', 'Aprovação exclusivamente para simulação registrada. Nenhuma transmissão fiscal.');
    }

    public function reenfileirar(int $id): void
    {
        abort_unless($this->empresaId, 422);
        app(ProcessamentoSimuladoNfse::class)->reenfileirar($this->empresaId, $id);
    }

    public function abrir(int $id): void
    {
        abort_unless($this->empresaId, 422);
        $empresa = app(AcessoEmissaoNfse::class)->empresa($this->empresaId);
        $this->detalheId = Simulacao::daEmpresa($empresa)->findOrFail($id)->id;
    }

    public function reconciliar(int $id): void
    {
        abort_unless($this->empresaId, 422);
        app(ProcessamentoSimuladoNfse::class)->reconciliar($this->empresaId, $id);
    }

    public function render()
    {
        abort_unless(app(PreparacaoNfse::class)->podeConfigurar(), 403);
        $empresa = $this->empresaId ? app(AcessoEmissaoNfse::class)->empresa($this->empresaId) : null;
        $lotes = collect();
        $simulacoes = collect();
        $itens = collect();
        $mesValido = (bool) preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $this->mes);
        if ($empresa && $mesValido) {
            $simQuery = Simulacao::daEmpresa($empresa)->whereIn('lote_id', Lote::daEmpresa($empresa)->where('competencia', $this->mes.'-01')->select('id'));
            $lotes = Lote::daEmpresa($empresa)->where('competencia', $this->mes.'-01')->where('status', 'preparado')
                ->whereNotIn('id', Simulacao::daEmpresa($empresa)->select('lote_id'))->latest('id')->paginate(20);
            $simulacoes = $simQuery->latest('id')->limit(50)->get(['id', 'lote_id', 'status', 'cenario', 'created_at']);
            if ($this->detalheId) {
                $sim = Simulacao::daEmpresa($empresa)->findOrFail($this->detalheId);
                $itens = SimulacaoItem::daEmpresa($empresa)->where('simulacao_id', $sim->id)->orderBy('nota_id')->get();
            }
        }
        return view('livewire.nfse.simulacoes', compact('empresa', 'lotes', 'simulacoes', 'itens', 'mesValido'))->layout($this->layout);
    }
}
