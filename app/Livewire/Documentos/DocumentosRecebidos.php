<?php

namespace App\Livewire\Documentos;

use App\Enums\Documentos\StatusDocumentoRecebido;
use App\Enums\Documentos\TipoDocumentoRecebido;
use App\Jobs\Documentos\ArquivarDocumentoRecebidoJob;
use App\Models\Documentos\DocumentoRecebido;
use App\Models\Empresa;
use App\Services\Documentos\ArquivarDocumentoService;
use App\Services\OperadoraContext;
use Livewire\Component;
use Livewire\WithPagination;

class DocumentosRecebidos extends Component
{
    use AutorizaModuloDocumentos;
    use WithPagination;

    protected $layout = 'components.layouts.app';

    public string $busca = '';

    public string $filtroStatus = '';

    public string $filtroEmpresa = '';

    public function mount(): void
    {
        $this->garantirAcessoDocumentos();
    }

    public function updatedBusca(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroStatus(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroEmpresa(): void
    {
        $this->resetPage();
    }

    public function alterarEmpresa(int $documentoId, $empresaId): void
    {
        $this->garantirAcessoDocumentos();

        $documento = DocumentoRecebido::query()->with('grupo.empresas')->findOrFail($documentoId);

        if ($documento->status === StatusDocumentoRecebido::EnviadoDrive) {
            return;
        }

        $empresaId = $empresaId === '' || $empresaId === null ? null : (int) $empresaId;

        if ($empresaId === null) {
            return;
        }

        $idsPermitidas = $documento->grupo?->idsEmpresas() ?? [];

        if ($idsPermitidas !== [] && ! in_array($empresaId, $idsPermitidas, true)) {
            return;
        }

        if (Empresa::query()->find($empresaId) === null) {
            return;
        }

        $documento->update([
            'empresa_id' => $empresaId,
            'erro_mensagem' => null,
        ]);

        ArquivarDocumentoRecebidoJob::dispatch($documento->id);
    }

    public function alterarTipo(int $documentoId, string $tipo): void
    {
        $this->garantirAcessoDocumentos();

        $documento = DocumentoRecebido::query()->findOrFail($documentoId);
        $enum = TipoDocumentoRecebido::tryFrom($tipo);

        if ($enum === null) {
            return;
        }

        $documento->update([
            'tipo_documento' => $enum,
            'status' => StatusDocumentoRecebido::Classificado,
            'erro_mensagem' => null,
        ]);

        ArquivarDocumentoRecebidoJob::dispatch($documento->id);
    }

    public function reprocessar(int $documentoId, ArquivarDocumentoService $arquivar): void
    {
        $this->garantirAcessoDocumentos();

        $documento = DocumentoRecebido::query()->findOrFail($documentoId);
        $arquivar->arquivar($documento, forcar: true);
    }

    public function render()
    {
        $documentos = collect();
        $empresas = collect();

        if (! $this->precisaSelecionarEscritorio() && OperadoraContext::id()) {
            $documentos = DocumentoRecebido::query()
                ->with(['empresa', 'grupo.empresas'])
                ->when($this->busca !== '', function ($query) {
                    $query->where('nome_original', 'like', '%'.$this->busca.'%');
                })
                ->when($this->filtroStatus !== '', fn ($q) => $q->where('status', $this->filtroStatus))
                ->when($this->filtroEmpresa !== '', fn ($q) => $q->where('empresa_id', (int) $this->filtroEmpresa))
                ->orderByDesc('id')
                ->paginate(20);

            $empresas = Empresa::query()->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'nome_fantasia']);
        }

        return view('livewire.documentos.documentos-recebidos', [
            'documentos' => $documentos,
            'empresas' => $empresas,
            'tipos' => TipoDocumentoRecebido::cases(),
            'statusLista' => StatusDocumentoRecebido::cases(),
            'precisaSelecionarEscritorio' => $this->precisaSelecionarEscritorio(),
        ]);
    }
}
