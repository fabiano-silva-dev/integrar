<?php

namespace App\Livewire\Nfse;

use App\Models\Nfse\Lote;
use App\Models\Nfse\Servico;
use App\Models\Nfse\Tomador;
use App\Services\Nfse\ConferenciaNfse;
use App\Services\Nfse\PreparacaoNfse;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Conferencia extends Component
{
    use WithPagination;

    #[Locked]
    public int $empresaId;

    public string $mes = '';
    public string $busca = '';
    public string $status = 'rascunho';
    public string $justificativa = '';

    #[Locked]
    public array $selecionadas = [];

    #[Locked]
    public array $versaoEdicao = [];

    public array $edicao = [];
    public array $massa = ['servico_id' => '', 'valor' => '', 'descricao' => ''];

    public function mount(int $empresaId): void
    {
        $this->empresaId = app(PreparacaoNfse::class)->empresa($empresaId)->id;
        $this->mes = now()->format('Y-m');
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, ['mes', 'busca', 'status'], true)) {
            $this->limparSelecao();
            $this->cancelarEdicao();
            $this->resetPage('paginaConferencia');
            $this->resetPage('paginaLotes');
        }
    }

    private function consulta(): Builder
    {
        $service = app(ConferenciaNfse::class);
        $valido = preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $this->mes);
        $query = $service->consulta($this->empresaId, $valido ? $this->mes : now()->format('Y-m'));
        if (! $valido || ! in_array($this->status, ['', 'rascunho', 'em_lote', 'descartada'], true)) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->status !== '') {
            $query->where('status', $this->status);
        }
        if ($this->busca !== '') {
            $busca = '%'.mb_substr($this->busca, 0, 100).'%';
            $query->where(fn ($q) => $q->where('snapshot->tomador->nome', 'like', $busca)->orWhere('snapshot->tomador->documento', 'like', $busca)->orWhere('snapshot->servico->nome', 'like', $busca)->orWhere('descricao', 'like', $busca));
        }
        return $query;
    }

    public function alternar(int $id): void
    {
        if (isset($this->selecionadas[$id])) {
            unset($this->selecionadas[$id]);
            return;
        }
        $nota = $this->consulta()->where('status', 'rascunho')->whereNull('lote_id')->findOrFail($id);
        if (count($this->selecionadas) >= ConferenciaNfse::LIMITE) {
            $this->addError('conferencia', 'Limite de 200 notas por seleção.');
            return;
        }
        $this->selecionadas[$nota->id] = $nota->versao;
    }

    public function selecionarTodas(): void
    {
        $notas = $this->consulta()->where('status', 'rascunho')->whereNull('lote_id')->orderBy('id')->limit(ConferenciaNfse::LIMITE + 1)->get(['id', 'versao']);
        if ($notas->count() > ConferenciaNfse::LIMITE) {
            $this->addError('conferencia', 'Há mais de 200 rascunhos. Restrinja os filtros antes de selecionar todos.');
            return;
        }
        $this->selecionadas = $notas->pluck('versao', 'id')->all();
    }

    public function limparSelecao(): void
    {
        $this->selecionadas = [];
        $this->justificativa = '';
        $this->resetValidation();
    }

    public function abrirEdicao(int $id): void
    {
        $nota = $this->consulta()->where('status', 'rascunho')->whereNull('lote_id')->findOrFail($id);
        $this->versaoEdicao = [$nota->id => $nota->versao];
        $this->edicao = $nota->only(['tomador_id', 'servico_id', 'valor', 'descricao']);
        $this->edicao['competencia'] = $nota->competencia->toDateString();
    }

    public function cancelarEdicao(): void
    {
        $this->versaoEdicao = [];
        $this->edicao = [];
    }

    public function salvarEdicao(): void
    {
        app(ConferenciaNfse::class)->editar($this->empresaId, $this->mes, $this->versaoEdicao, $this->edicao);
        $this->cancelarEdicao();
        $this->limparSelecao();
        session()->flash('nfse_conferencia_mensagem', 'Rascunho atualizado.');
    }

    public function alterarMassa(): void
    {
        $dados = array_filter($this->massa, fn ($valor) => $valor !== '' && $valor !== null);
        app(ConferenciaNfse::class)->editar($this->empresaId, $this->mes, $this->selecionadas, $dados);
        $this->reset('massa');
        $this->limparSelecao();
        session()->flash('nfse_conferencia_mensagem', 'Rascunhos atualizados. Revise antes de preparar o lote.');
    }

    public function descartar(): void
    {
        app(ConferenciaNfse::class)->descartar($this->empresaId, $this->mes, $this->selecionadas);
        $this->limparSelecao();
        session()->flash('nfse_conferencia_mensagem', 'Rascunhos descartados; histórico preservado.');
    }

    public function prepararLote(): void
    {
        $lote = app(ConferenciaNfse::class)->prepararLote($this->empresaId, $this->mes, $this->selecionadas, $this->justificativa);
        $this->limparSelecao();
        session()->flash('nfse_conferencia_mensagem', "Lote #{$lote->id} preparado. Nenhuma NFS-e transmitida ou aprovada.");
    }

    public function desfazerLote(int $id): void
    {
        app(ConferenciaNfse::class)->desfazerLote($this->empresaId, $id);
        $this->limparSelecao();
        session()->flash('nfse_conferencia_mensagem', 'Lote desfeito. Notas voltaram a rascunho.');
    }

    public function render()
    {
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        $service = app(ConferenciaNfse::class);
        $notas = $this->consulta()->latest('id')->paginate(20, ['*'], 'paginaConferencia');
        $detalhes = [];
        foreach ($notas as $nota) {
            $detalhes[$nota->id] = ['anterior' => $service->anterior($empresa, $nota), 'alertas' => $nota->status === 'rascunho' ? $service->alertas($empresa, $nota) : []];
        }
        $selecionadas = collect();
        $selecaoValida = true;
        if ($this->selecionadas !== []) {
            try {
                $selecionadas = $service->selecionadas($empresa->id, $this->mes, $this->selecionadas);
            } catch (\Illuminate\Validation\ValidationException) {
                $selecaoValida = false;
            }
        }
        $total = ConferenciaNfse::total($selecionadas);
        $servicos = Servico::daEmpresa($empresa)->where('ativo', true)->orderBy('nome')->get(['id', 'nome']);
        $tomadores = $this->versaoEdicao === [] ? collect() : Tomador::daEmpresa($empresa)->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'documento']);
        $alertasSelecionados = [];
        foreach ($selecionadas as $nota) {
            $alertas = $detalhes[$nota->id]['alertas'] ?? $service->alertas($empresa, $nota);
            if ($alertas !== []) {
                $alertasSelecionados[] = $nota->id;
            }
        }
        $lotesQuery = Lote::daEmpresa($empresa);
        if (preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $this->mes)) {
            $lotesQuery->where('competencia', $this->mes.'-01');
        } else {
            $lotesQuery->whereRaw('1 = 0');
        }
        $lotes = $lotesQuery->latest('id')->paginate(10, ['*'], 'paginaLotes');
        return view('livewire.nfse.conferencia', compact('notas', 'detalhes', 'total', 'selecaoValida', 'servicos', 'tomadores', 'alertasSelecionados', 'lotes'));
    }
}
