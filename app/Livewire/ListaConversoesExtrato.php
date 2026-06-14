<?php

namespace App\Livewire;

use App\Models\ConversaoExtrato;
use App\Services\OperadoraStorage;
use App\Services\ConversaoPdfOfxService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class ListaConversoesExtrato extends Component
{
    use WithPagination;

    public $filtroStatus = '';
    public $filtroLayout = '';
    public $filtroData = '';
    public $filtroArquivo = '';

    protected $queryString = [
        'filtroStatus' => ['except' => ''],
        'filtroLayout' => ['except' => ''],
        'filtroData' => ['except' => ''],
        'filtroArquivo' => ['except' => ''],
    ];

    protected $servico;

    public function mount(): void
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'admin') {
            abort(403, 'Acesso não autorizado.');
        }
    }

    public function boot(ConversaoPdfOfxService $servico): void
    {
        $this->servico = $servico;
    }

    public function limparFiltros(): void
    {
        $this->filtroStatus = '';
        $this->filtroLayout = '';
        $this->filtroData = '';
        $this->filtroArquivo = '';
        $this->resetPage();
    }

    public function downloadOfx(int $conversaoId)
    {
        $conversao = $this->buscarConversao($conversaoId);

        if (!$conversao || $conversao->status !== 'concluida' || !$conversao->nome_arquivo_ofx) {
            session()->flash('error', 'Arquivo OFX não disponível para download.');
            return null;
        }

        $caminho = OperadoraStorage::resolveAbsolutePath('exports', $conversao->nome_arquivo_ofx);

        if (!$caminho || !file_exists($caminho)) {
            session()->flash('error', 'Arquivo OFX não encontrado no servidor.');
            return null;
        }

        return response()->download($caminho, $conversao->nome_arquivo_ofx);
    }

    private function buscarConversao(int $conversaoId): ?ConversaoExtrato
    {
        return ConversaoExtrato::query()->where('id', $conversaoId)->first();
    }

    private function getConversoesQuery()
    {
        $query = ConversaoExtrato::query();

        if (!empty($this->filtroStatus)) {
            $query->where('status', $this->filtroStatus);
        }

        if (!empty($this->filtroLayout)) {
            $query->where('layout', $this->filtroLayout);
        }

        if (!empty($this->filtroData)) {
            $query->whereDate('created_at', $this->filtroData);
        }

        if (!empty($this->filtroArquivo)) {
            $query->where(function ($q) {
                $q->where('nome_arquivo_origem', 'like', '%' . $this->filtroArquivo . '%')
                    ->orWhere('nome_arquivo_ofx', 'like', '%' . $this->filtroArquivo . '%');
            });
        }

        return $query->orderByDesc('created_at');
    }

    public function render()
    {
        $layoutsDisponiveis = [];
        foreach ($this->servico->layoutsPdfPorFamilia() as $layouts) {
            foreach ($layouts as $valor => $nome) {
                $layoutsDisponiveis[$valor] = $nome;
            }
        }

        return view('livewire.lista-conversoes-extrato', [
            'conversoes' => $this->getConversoesQuery()
                ->with('user')
                ->paginate(15),
            'layoutsDisponiveis' => $layoutsDisponiveis,
        ]);
    }
}
