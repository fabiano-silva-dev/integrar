<?php

namespace App\Livewire\Nfse;

use App\Models\Nfse\Recorrencia;
use App\Models\Nfse\Servico;
use App\Models\Nfse\Tomador;
use App\Services\Nfse\PreparacaoNfse;
use App\Services\Nfse\RecorrenciasNfse;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Recorrencias extends Component
{
    use WithPagination;

    #[Locked]
    public int $empresaId;
    public string $mes = '';
    public array $form = [];
    public array $reajuste = [];
    #[Locked]
    public ?int $reajustando = null;
    #[Locked]
    public array $previa = [];

    public function mount(int $empresaId): void
    {
        $this->empresaId = app(PreparacaoNfse::class)->empresa($empresaId)->id;
        $this->mes = now()->format('Y-m');
        $this->limpar();
    }

    public function limpar(): void
    {
        $this->form = ['referencia' => '', 'tomador_id' => '', 'servico_id' => '', 'inicio' => $this->mes, 'fim' => '', 'dia' => 1, 'valor' => '', 'descricao' => ''];
    }

    public function updatedMes(): void
    {
        $this->previa = [];
        $this->reajustando = null;
        $this->resetValidation();
    }

    public function updatedFormServicoId($id): void
    {
        if (! $id) {
            return;
        }
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        $servico = Servico::daEmpresa($empresa)->where('ativo', true)->findOrFail($id);
        $this->form['descricao'] = $servico->descricao;
        $this->form['valor'] = $servico->valor_padrao ?? '';
    }

    public function cadastrar(): void
    {
        app(RecorrenciasNfse::class)->cadastrar($this->empresaId, array_map(fn ($v) => $v === '' ? null : $v, $this->form));
        $this->limpar();
        $this->previa = [];
        $this->resetPage('paginaRecorrencias');
        session()->flash('nfse_rec_mensagem', 'Contrato cadastrado.');
    }

    public function abrirReajuste(int $id): void
    {
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        $this->reajustando = Recorrencia::daEmpresa($empresa)->findOrFail($id)->id;
        $this->reajuste = ['mes' => $this->mes, 'valor' => '', 'descricao' => ''];
    }

    public function salvarReajuste(): void
    {
        abort_unless($this->reajustando, 422);
        app(RecorrenciasNfse::class)->reajustar($this->empresaId, $this->reajustando, (string) ($this->reajuste['mes'] ?? ''), $this->reajuste);
        $this->reajustando = null;
        $this->previa = [];
        session()->flash('nfse_rec_mensagem', 'Nova condição registrada. Notas existentes não foram alteradas.');
    }

    public function cancelarReajuste(): void
    {
        $this->reajustando = null;
    }

    public function pausar(int $id, bool $pausada): void
    {
        app(RecorrenciasNfse::class)->pausar($this->empresaId, $id, $this->mes, $pausada);
        $this->conferir();
    }

    public function encerrar(int $id): void
    {
        app(RecorrenciasNfse::class)->encerrar($this->empresaId, $id, $this->mes);
        $this->previa = [];
        session()->flash('nfse_rec_mensagem', 'Vigência encerrada ao final da competência selecionada.');
    }

    public function conferir(): void
    {
        $this->previa = [];
        $r = app(RecorrenciasNfse::class)->previa($this->empresaId, $this->mes);
        $this->previa = array_intersect_key($r, array_flip(['aptas', 'total', 'conferencia']));
        $this->previa['linhas'] = array_map(fn ($l) => array_diff_key($l, array_flip(['dados', 'cadastro_hash'])), $r['linhas']);
    }

    public function gerar(): void
    {
        if (! isset($this->previa['conferencia'])) {
            $this->addError('recorrencia', 'Confira as recorrências antes de gerar.');
            return;
        }
        $quantidade = app(RecorrenciasNfse::class)->gerar($this->empresaId, $this->mes, $this->previa['conferencia']);
        $this->conferir();
        session()->flash('nfse_rec_mensagem', $quantidade.' rascunhos gerados. Revise na aba Conferência e lotes. Nenhuma nota transmitida.');
    }

    public function render()
    {
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        $tomadores = Tomador::daEmpresa($empresa)->orderBy('nome')->get(['id', 'nome', 'documento', 'ativo']);
        $servicos = Servico::daEmpresa($empresa)->orderBy('nome')->get(['id', 'nome', 'ativo']);
        $contratos = Recorrencia::daEmpresa($empresa)->latest('id')->paginate(20, ['*'], 'paginaRecorrencias');
        return view('livewire.nfse.recorrencias', compact('tomadores', 'servicos', 'contratos'));
    }
}
