<?php

namespace App\Livewire\Documentos;

use App\Enums\Documentos\StatusConexaoWhatsapp;
use App\Models\Documentos\GrupoWhatsapp;
use App\Models\Empresa;
use App\Services\Documentos\EvolutionConexaoService;
use App\Services\OperadoraContext;
use Livewire\Component;
use Livewire\WithPagination;

class GruposWhatsapp extends Component
{
    use AutorizaModuloDocumentos;
    use WithPagination;

    protected $layout = 'components.layouts.app';

    public string $busca = '';

    public ?string $erro = null;

    public ?string $sucesso = null;

    public function mount(): void
    {
        $this->garantirAcessoDocumentos();
    }

    public function updatedBusca(): void
    {
        $this->resetPage();
    }

    public function sincronizar(EvolutionConexaoService $conexoes): void
    {
        $this->garantirAcessoDocumentos();
        $this->erro = null;
        $this->sucesso = null;

        if ($this->precisaSelecionarEscritorio()) {
            return;
        }

        try {
            $conexao = $conexoes->garantirConexao();

            if ($conexao->status !== StatusConexaoWhatsapp::Conectado) {
                $this->erro = 'Conecte o WhatsApp antes de sincronizar os grupos.';

                return;
            }

            $total = $conexoes->sincronizarGrupos($conexao);
            $this->sucesso = $total === 1
                ? '1 grupo sincronizado.'
                : "{$total} grupos sincronizados.";
        } catch (\Throwable $exception) {
            $this->erro = $exception->getMessage();
        }
    }

    public function alterarEmpresa(int $grupoId, $empresaId): void
    {
        $this->garantirAcessoDocumentos();

        $grupo = GrupoWhatsapp::query()->findOrFail($grupoId);
        $empresaId = $empresaId === '' || $empresaId === null ? null : (int) $empresaId;

        if ($empresaId !== null && Empresa::query()->find($empresaId) === null) {
            $this->erro = 'A empresa selecionada não pertence ao seu escritório.';

            return;
        }

        $grupo->update([
            'empresa_id' => $empresaId,
            'monitorar' => $empresaId !== null ? $grupo->monitorar : false,
        ]);
    }

    public function alternarMonitorar(int $grupoId): void
    {
        $this->garantirAcessoDocumentos();

        $grupo = GrupoWhatsapp::query()->findOrFail($grupoId);

        if ($grupo->empresa_id === null && ! $grupo->monitorar) {
            $this->erro = 'Vincule uma empresa antes de monitorar o grupo.';

            return;
        }

        $grupo->update(['monitorar' => ! $grupo->monitorar]);
    }

    public function render()
    {
        $grupos = collect();
        $empresas = collect();

        if (! $this->precisaSelecionarEscritorio() && OperadoraContext::id()) {
            $grupos = GrupoWhatsapp::query()
                ->with('empresa')
                ->when($this->busca !== '', function ($query) {
                    $query->where(function ($q) {
                        $q->where('nome', 'like', '%'.$this->busca.'%')
                            ->orWhere('jid', 'like', '%'.$this->busca.'%');
                    });
                })
                ->orderByRaw('monitorar desc')
                ->orderBy('nome')
                ->paginate(20);

            $empresas = Empresa::query()->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'nome_fantasia', 'razao_social']);
        }

        return view('livewire.documentos.grupos-whatsapp', [
            'grupos' => $grupos,
            'empresas' => $empresas,
            'precisaSelecionarEscritorio' => $this->precisaSelecionarEscritorio(),
        ]);
    }
}
