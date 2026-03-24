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
        $importacao = $this->getImportacaoDaEmpresaSelecionada((int) $importacaoId);

        if (!$importacao) {
            session()->flash('error', 'Importação não encontrada para a empresa selecionada.');
            return;
        }

        return redirect()->route('tabela', ['importacao' => $importacao->id]);
    }

    public function excluirImportacao($importacaoId)
    {
        $importacao = $this->getImportacaoDaEmpresaSelecionada((int) $importacaoId);
        
        if (!$importacao) {
            session()->flash('error', 'Importação não encontrada para a empresa selecionada.');
            return;
        }

        try {
            // Excluir todos os lançamentos da importação
            $lancamentosExcluidos = \App\Models\Lancamento::where('importacao_id', $importacao->id)->delete();
            
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
        $empresaSelecionadaId = session('empresa_selecionada_id');

        if (!empty($empresaSelecionadaId)) {
            $query->where('empresa_id', (int) $empresaSelecionadaId);
        }

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

    private function getImportacaoDaEmpresaSelecionada(int $importacaoId): ?Importacao
    {
        $query = Importacao::query()->where('id', $importacaoId);
        $empresaSelecionadaId = session('empresa_selecionada_id');

        if (!empty($empresaSelecionadaId)) {
            $query->where('empresa_id', (int) $empresaSelecionadaId);
        }

        return $query->first();
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
