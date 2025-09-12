<?php

namespace App\Livewire;

use App\Models\Importacao;
use Livewire\Component;
use Livewire\WithPagination;

class ListaImportacoes extends Component
{
    use WithPagination;

    protected $layout = 'components.layouts.app';

    public $filtroStatus = '';
    public $filtroData = '';
    public $filtroArquivo = '';

    protected $queryString = [
        'filtroStatus' => ['except' => ''],
        'filtroData' => ['except' => ''],
        'filtroArquivo' => ['except' => ''],
    ];

    public function atualizarFiltros()
    {
        $this->resetPage();
    }

    public function limparFiltros()
    {
        $this->filtroStatus = '';
        $this->filtroData = '';
        $this->filtroArquivo = '';
        $this->resetPage();
    }

    public function abrirImportacao($importacaoId)
    {
        return redirect()->route('tabela', ['importacao' => $importacaoId]);
    }

    public function excluirImportacao($importacaoId)
    {
        $importacao = Importacao::find($importacaoId);
        
        if (!$importacao) {
            session()->flash('error', 'Importação não encontrada.');
            return;
        }

        try {
            // Excluir todos os lançamentos da importação
            $lancamentosExcluidos = \App\Models\Lancamento::where('importacao_id', $importacaoId)->delete();
            
            // Excluir a importação
            $importacao->delete();
            
            session()->flash('success', "Importação excluída com sucesso! {$lancamentosExcluidos} lançamentos foram removidos.");
            
        } catch (\Exception $e) {
            session()->flash('error', 'Erro ao excluir importação: ' . $e->getMessage());
        }
    }

    private function getImportacoesQuery()
    {
        $query = Importacao::query();

        if (!empty($this->filtroStatus)) {
            $query->where('status', $this->filtroStatus);
        }

        if (!empty($this->filtroData)) {
            $query->whereDate('created_at', $this->filtroData);
        }

        if (!empty($this->filtroArquivo)) {
            $query->where('nome_arquivo', 'like', '%' . $this->filtroArquivo . '%');
        }

        return $query->orderBy('created_at', 'desc');
    }

    public function render()
    {
        $importacoes = $this->getImportacoesQuery()
            ->withCount('lancamentos') // Adicionar contagem de lançamentos
            ->with('user') // Carregar relacionamento com usuário
            ->paginate(15);

        return view('livewire.lista-importacoes', [
            'importacoes' => $importacoes
        ]);
    }
}
