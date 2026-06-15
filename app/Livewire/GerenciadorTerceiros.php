<?php

namespace App\Livewire;

use App\Models\Empresa;
use App\Models\Terceiro;
use App\Rules\CnpjOuCpfValido;
use App\Services\OperadoraContext;
use Livewire\Component;
use Livewire\WithPagination;

class GerenciadorTerceiros extends Component
{
    use WithPagination;

    protected $layout = 'components.layouts.app';

    public $empresa_id = null;
    public $filtroNome = '';
    public $filtroTipo = '';
    public $filtroAtivo = '';
    
    // Modal de edição
    public $editandoId = null;
    public $nome = '';
    public $cnpj_cpf = '';
    public $tipo = 'empresa';
    public $observacoes = '';
    public $ativo = true;

    protected $queryString = [
        'filtroNome' => ['except' => ''],
        'filtroTipo' => ['except' => ''],
        'filtroAtivo' => ['except' => ''],
    ];

    protected function rules()
    {
        return [
            'nome' => 'required|string|max:255',
            'cnpj_cpf' => ['nullable', 'string', 'max:18', new CnpjOuCpfValido()],
            'tipo' => 'required|in:empresa,cliente,funcionario,fornecedor',
            'observacoes' => 'nullable|string',
            'ativo' => 'boolean',
        ];
    }

    public function mount()
    {
        $this->empresa_id = session('empresa_selecionada_id');
    }

    public function atualizarFiltros()
    {
        $this->resetPage();
    }

    public function limparFiltros()
    {
        $this->filtroNome = '';
        $this->filtroTipo = '';
        $this->filtroAtivo = '';
        $this->resetPage();
    }

    public function abrirModal($terceiroId = null)
    {
        if ($terceiroId) {
            if (!$this->empresa_id) {
                return;
            }

            $terceiro = Terceiro::where('empresa_id', $this->empresa_id)->findOrFail($terceiroId);
            if ($terceiro) {
                $this->editandoId = $terceiro->id;
                $this->nome = $terceiro->nome;
                $this->cnpj_cpf = $terceiro->cnpj_cpf;
                $this->tipo = $terceiro->tipo;
                $this->observacoes = $terceiro->observacoes;
                $this->ativo = $terceiro->ativo;
            }
        } else {
            $this->limparFormulario();
        }
    }

    public function salvar()
    {
        if (!$this->empresa_id) {
            $this->addError('nome', 'Selecione uma empresa no cabeçalho antes de cadastrar terceiros.');
            return;
        }

        $empresa = OperadoraContext::resolveEmpresa($this->empresa_id);
        if (!$empresa) {
            $this->addError('nome', 'Empresa inválida para o escritório atual.');
            return;
        }

        $this->validate();

        $cnpjCpf = CnpjOuCpfValido::format($this->cnpj_cpf);

        if ($this->editandoId) {
            $terceiro = Terceiro::where('empresa_id', $empresa->id)->findOrFail($this->editandoId);
            if ($terceiro) {
                $terceiro->update([
                    'nome' => $this->nome,
                    'cnpj_cpf' => $cnpjCpf,
                    'tipo' => $this->tipo,
                    'observacoes' => $this->observacoes,
                    'ativo' => $this->ativo
                ]);
            }
        } else {
            Terceiro::create([
                'nome' => $this->nome,
                'cnpj_cpf' => $cnpjCpf,
                'tipo' => $this->tipo,
                'observacoes' => $this->observacoes,
                'ativo' => $this->ativo,
                'empresa_id' => $empresa->id,
            ]);
        }

        $this->fecharModal();
    }

    public function excluir($terceiroId)
    {
        if (!$this->empresa_id) {
            return;
        }

        $terceiro = Terceiro::where('empresa_id', $this->empresa_id)->findOrFail($terceiroId);
        if ($terceiro) {
            $terceiro->delete();
        }
    }

    public function toggleAtivo($terceiroId)
    {
        if (!$this->empresa_id) {
            return;
        }

        $terceiro = Terceiro::where('empresa_id', $this->empresa_id)->findOrFail($terceiroId);
        if ($terceiro) {
            $terceiro->update(['ativo' => !$terceiro->ativo]);
        }
    }

    public function fecharModal()
    {
        $this->editandoId = null;
        $this->limparFormulario();
    }

    private function limparFormulario()
    {
        $this->nome = '';
        $this->cnpj_cpf = '';
        $this->tipo = 'empresa';
        $this->observacoes = '';
        $this->ativo = true;
    }

    private function getTerceirosQuery()
    {
        $query = Terceiro::query();

        if ($this->empresa_id) {
            $query->where('empresa_id', $this->empresa_id);
        } else {
            $query->whereRaw('1 = 0');
        }

        if (!empty($this->filtroNome)) {
            $query->where('nome', 'like', '%' . $this->filtroNome . '%');
        }

        if (!empty($this->filtroTipo)) {
            $query->where('tipo', $this->filtroTipo);
        }

        if ($this->filtroAtivo !== '') {
            $query->where('ativo', $this->filtroAtivo);
        }

        return $query->orderBy('nome');
    }

    public function render()
    {
        $terceiros = $this->getTerceirosQuery()->paginate(15);
        $empresaAtual = $this->empresa_id
            ? Empresa::find($this->empresa_id)
            : null;

        return view('livewire.gerenciador-terceiros', [
            'terceiros' => $terceiros,
            'empresaAtual' => $empresaAtual,
        ]);
    }
}
