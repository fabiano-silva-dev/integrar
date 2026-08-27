<?php

namespace App\Livewire\Documentos;

use App\Models\Documentos\DocumentoProcessoLog;
use App\Services\OperadoraContext;
use Livewire\Component;
use Livewire\WithPagination;

class DocumentosProcessoLog extends Component
{
    use AutorizaModuloDocumentos;
    use WithPagination;

    protected $layout = 'components.layouts.app';

    public string $busca = '';

    public string $filtroEtapa = '';

    public string $filtroNivel = '';

    public function mount(): void
    {
        $this->garantirAcessoLogDocumentos();
    }

    public function updatedBusca(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroEtapa(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroNivel(): void
    {
        $this->resetPage();
    }

    public function limpar(): void
    {
        $this->garantirAcessoLogDocumentos();

        $operadoraId = OperadoraContext::id();

        if ($operadoraId === null) {
            return;
        }

        DocumentoProcessoLog::query()
            ->where('empresa_operadora_id', $operadoraId)
            ->delete();

        $this->resetPage();
    }

    public function render()
    {
        $logs = collect();
        $operadoraId = OperadoraContext::id();

        if (! $this->precisaSelecionarEscritorio() && $operadoraId) {
            $logs = DocumentoProcessoLog::query()
                ->where('empresa_operadora_id', $operadoraId)
                ->when($this->busca !== '', function ($query) {
                    $query->where(function ($inner) {
                        $inner->where('mensagem', 'like', '%'.$this->busca.'%')
                            ->orWhere('mensagem_whatsapp_id', 'like', '%'.$this->busca.'%')
                            ->orWhere('contexto', 'like', '%'.$this->busca.'%');
                    });
                })
                ->when($this->filtroEtapa !== '', fn ($q) => $q->where('etapa', $this->filtroEtapa))
                ->when($this->filtroNivel !== '', fn ($q) => $q->where('nivel', $this->filtroNivel))
                ->orderByDesc('id')
                ->paginate(40);
        }

        return view('livewire.documentos.documentos-processo-log', [
            'logs' => $logs,
            'etapas' => DocumentoProcessoLog::etapas(),
            'niveis' => DocumentoProcessoLog::niveis(),
            'debugAtivo' => (bool) config('documentos.debug', false),
            'precisaSelecionarEscritorio' => $this->precisaSelecionarEscritorio(),
        ]);
    }
}
